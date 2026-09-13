#!/usr/bin/env python3
"""Auditoría metodológica del modelo de alerta temprana EduSync.

Incluye dos validaciones complementarias:
1) Repeated GroupShuffleSplit por student_id: estima generalización a estudiantes
   no vistos dentro del conjunto histórico.
2) Walk-forward temporal: cada periodo se prueba entrenando exclusivamente con
   periodos cuya fecha de corte es anterior. El último periodo válido funciona
   como holdout temporal principal y simula mejor el uso real.

El dataset debe seguir la política temporal v3:
bimestre N cerrado -> resultado observado al cierre del bimestre N+1.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import math
from collections import Counter
from datetime import date
from pathlib import Path
from statistics import mean, pstdev

import numpy as np
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    accuracy_score,
    average_precision_score,
    balanced_accuracy_score,
    confusion_matrix,
    f1_score,
    precision_score,
    recall_score,
    roc_auc_score,
)
from sklearn.model_selection import GroupShuffleSplit
from sklearn.preprocessing import StandardScaler

FEATURES = [
    "grade_mean_current",
    "grade_mean_previous",
    "grade_trend",
    "critical_records_current",
    "critical_courses_current",
    "attendance_rate_30d",
    "late_30d",
    "absent_30d",
]
TARGET = "target_next_bimester_risk"


def args_parser():
    p = argparse.ArgumentParser(description="Valida el dataset/modelo de riesgo académico EduSync")
    p.add_argument("--input", required=True)
    p.add_argument("--model", default="edusync/storage/ai_models/risk_model.json")
    p.add_argument("--output", default="storage/risk_validation_report.json")
    p.add_argument("--splits", type=int, default=10)
    p.add_argument("--test-size", type=float, default=0.25)
    p.add_argument("--seed", type=int, default=42)
    p.add_argument("--threshold", type=float, default=0.50)
    p.add_argument("--max-cases", type=int, default=15)
    p.add_argument("--include-student-id", action="store_true",
                   help="Incluye student_id real en la auditoría local. Por defecto se anonimiza.")
    return p.parse_args()


def num(value):
    text = "" if value is None else str(value).strip().replace(",", ".")
    return float("nan") if text == "" else float(text)


def parse_date(value):
    text = str(value or "").strip()
    if not text:
        return None
    try:
        return date.fromisoformat(text)
    except ValueError:
        return None


def anonymize_student(student_id):
    return "S-" + hashlib.sha256(str(student_id).encode("utf-8")).hexdigest()[:10]


def load(path: Path):
    records = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        fields = set(reader.fieldnames or [])
        required = ["student_id","academic_year_id","bimester","target_bimester","cutoff_date","cutoff_source",TARGET,*FEATURES]
        missing = [x for x in required if x not in fields]
        if missing:
            raise SystemExit("Faltan columnas del esquema temporal v3: " + ", ".join(missing) + ". Reexporta el dataset.")
        for row in reader:
            try:
                x = [num(row.get(feature)) for feature in FEATURES]
                y = int(float(row[TARGET]))
                student = str(row["student_id"]).strip()
                year_id = int(float(row["academic_year_id"]))
                base = int(float(row["bimester"]))
                target = int(float(row["target_bimester"]))
                cutoff = parse_date(row.get("cutoff_date"))
            except (ValueError, TypeError):
                continue
            if y not in (0,1) or not student or target != base + 1 or cutoff is None:
                continue
            if not all(math.isfinite(v) for v in x[:5]):
                continue
            records.append({
                "x": x, "y": y, "student_id": student, "academic_year_id": year_id,
                "bimester": base, "target_bimester": target, "cutoff_date": cutoff, "row": row
            })
    if not records:
        raise SystemExit("No hay filas válidas.")
    X = np.asarray([r["x"] for r in records], dtype=float)
    y = np.asarray([r["y"] for r in records], dtype=int)
    groups = np.asarray([r["student_id"] for r in records])
    return records, X, y, groups


def metrics(y_true, prob, threshold):
    pred = (prob >= threshold).astype(int)
    result = {
        "accuracy": float(accuracy_score(y_true, pred)),
        "balanced_accuracy": float(balanced_accuracy_score(y_true, pred)),
        "precision": float(precision_score(y_true, pred, zero_division=0)),
        "recall": float(recall_score(y_true, pred, zero_division=0)),
        "f1": float(f1_score(y_true, pred, zero_division=0)),
        "confusion_matrix": confusion_matrix(y_true, pred, labels=[0,1]).tolist(),
    }
    if len(np.unique(y_true)) == 2:
        result["roc_auc"] = float(roc_auc_score(y_true, prob))
        result["pr_auc"] = float(average_precision_score(y_true, prob))
    else:
        result["roc_auc"] = None
        result["pr_auc"] = None
    return result, pred


def summarize(values):
    clean = [float(x) for x in values if x is not None and math.isfinite(float(x))]
    if not clean:
        return {"mean": None, "std": None, "min": None, "max": None}
    return {"mean": mean(clean), "std": pstdev(clean), "min": min(clean), "max": max(clean)}


def count_by(records, field):
    out = {}
    for rec in records:
        key = str(rec["row"].get(field, "") or "sin_dato")
        out[key] = out.get(key, 0) + 1
    return dict(sorted(out.items(), key=lambda kv: kv[0]))


def pair_counts(records):
    out = {}
    for rec in records:
        key = f"{rec['bimester']}->{rec['target_bimester']}"
        out[key] = out.get(key, 0) + 1
    return dict(sorted(out.items()))


def fit_predict(X, y, train_idx, test_idx, seed, threshold):
    y_train = y[train_idx]
    y_test = y[test_idx]
    if len(np.unique(y_train)) < 2 or len(np.unique(y_test)) < 2:
        return None
    imputer = SimpleImputer(strategy="median")
    X_train = imputer.fit_transform(X[train_idx])
    X_test = imputer.transform(X[test_idx])
    scaler = StandardScaler()
    X_train = scaler.fit_transform(X_train)
    X_test = scaler.transform(X_test)
    model = LogisticRegression(class_weight="balanced", max_iter=3000, random_state=seed)
    model.fit(X_train, y_train)
    prob = model.predict_proba(X_test)[:, 1]
    bundle, pred = metrics(y_test, prob, threshold)
    return {"metrics": bundle, "probability": prob, "prediction": pred, "y_test": y_test}


def case_type(actual, predicted):
    if predicted == 1 and actual == 1: return "verdadero_positivo"
    if predicted == 0 and actual == 0: return "verdadero_negativo"
    if predicted == 1: return "falso_positivo"
    return "falso_negativo"


def audit_case(rec, actual, predicted, probability, threshold, include_student_id):
    row = rec["row"]
    item = {
        "student_key": anonymize_student(rec["student_id"]),
        "academic_year_id": rec["academic_year_id"],
        "bimester": rec["bimester"],
        "target_bimester": rec["target_bimester"],
        "cutoff_date": rec["cutoff_date"].isoformat(),
        "nivel": row.get("nivel"),
        "grado": row.get("grado"),
        "seccion": row.get("seccion"),
        "actual": int(actual),
        "predicted": int(predicted),
        "probability": float(probability),
        "distance_to_threshold": float(abs(float(probability) - threshold)),
        "case_type": case_type(int(actual), int(predicted)),
        "attendance_available": str(row.get("attendance_records_30d", "")).strip() not in ("", "0", "0.0"),
        "features": {},
    }
    if include_student_id:
        item["student_id"] = rec["student_id"]
    for feature in FEATURES:
        raw = row.get(feature)
        if raw is None or str(raw).strip() == "":
            item["features"][feature] = None
        else:
            try:
                item["features"][feature] = float(str(raw).replace(",", "."))
            except ValueError:
                item["features"][feature] = None
    return item


def feature_profile(cases):
    if not cases:
        return {"count": 0, "attendance_missing_rate": None, "feature_means": {}}
    means = {}
    for feature in FEATURES:
        vals = [c["features"].get(feature) for c in cases if c["features"].get(feature) is not None]
        means[feature] = float(mean(vals)) if vals else None
    return {
        "count": len(cases),
        "attendance_missing_rate": float(mean([0.0 if c["attendance_available"] else 1.0 for c in cases])),
        "feature_means": means,
    }


def cases_summary(cases):
    by_type = Counter(c["case_type"] for c in cases)
    by_pair = Counter(f"{c['bimester']}->{c['target_bimester']}" for c in cases)
    by_level = Counter(str(c.get("nivel") or "sin_dato") for c in cases)
    return {
        "count_by_type": dict(sorted(by_type.items())),
        "count_by_pair": dict(sorted(by_pair.items())),
        "count_by_level": dict(sorted(by_level.items())),
        "false_negative_profile": feature_profile([c for c in cases if c["case_type"] == "falso_negativo"]),
        "false_positive_profile": feature_profile([c for c in cases if c["case_type"] == "falso_positivo"]),
    }


def select_cases(cases, max_cases):
    tp = sorted([c for c in cases if c["case_type"] == "verdadero_positivo"], key=lambda c: -c["probability"])[:max_cases]
    tn = sorted([c for c in cases if c["case_type"] == "verdadero_negativo"], key=lambda c: c["probability"])[:max_cases]
    fp = sorted([c for c in cases if c["case_type"] == "falso_positivo"], key=lambda c: -c["probability"])[:max_cases]
    fn = sorted([c for c in cases if c["case_type"] == "falso_negativo"], key=lambda c: c["probability"])[:max_cases]
    return {"true_positives": tp, "true_negatives": tn, "false_positives": fp, "false_negatives": fn}


def grouped_validation(records, X, y, groups, args):
    splitter = GroupShuffleSplit(n_splits=max(2,args.splits), test_size=args.test_size, random_state=args.seed)
    split_metrics = []
    reference = None
    for split_no, (train_idx, test_idx) in enumerate(splitter.split(X, y, groups=groups), start=1):
        result = fit_predict(X, y, train_idx, test_idx, args.seed + split_no, args.threshold)
        if result is None:
            continue
        bundle = dict(result["metrics"])
        bundle.update({"split": split_no, "train_rows": int(len(train_idx)), "test_rows": int(len(test_idx)),
                       "test_students": int(len(np.unique(groups[test_idx])))})
        split_metrics.append(bundle)
        if reference is None:
            cases = []
            for local_i, global_i in enumerate(test_idx):
                cases.append(audit_case(records[global_i], int(result["y_test"][local_i]),
                                        int(result["prediction"][local_i]), float(result["probability"][local_i]),
                                        args.threshold, args.include_student_id))
            reference = {"metrics": bundle, "case_summary": cases_summary(cases),
                         "cases": select_cases(cases, args.max_cases)}
    if not split_metrics:
        raise SystemExit("Ningún split agrupado produjo ambas clases en entrenamiento y prueba.")
    names = ["accuracy","balanced_accuracy","precision","recall","f1","roc_auc","pr_auc"]
    repeated = {name: summarize([m.get(name) for m in split_metrics]) for name in names}
    return {"method": "Repeated GroupShuffleSplit por student_id", "requested_splits": int(args.splits),
            "valid_splits": int(len(split_metrics)), "test_size": float(args.test_size),
            "decision_threshold": float(args.threshold), "metrics_summary": repeated,
            "splits": split_metrics, "reference_split": reference}


def temporal_periods(records):
    groups = {}
    for idx, rec in enumerate(records):
        key = (rec["cutoff_date"], rec["academic_year_id"], rec["bimester"], rec["target_bimester"])
        groups.setdefault(key, []).append(idx)
    return sorted(groups.items(), key=lambda item: item[0])


def temporal_validation(records, X, y, args):
    periods = temporal_periods(records)
    evaluations = []
    all_cases = []
    for period_no, (key, test_indices) in enumerate(periods, start=1):
        cutoff, year_id, base, target = key
        train_indices = [idx for idx, rec in enumerate(records) if rec["cutoff_date"] < cutoff]
        if not train_indices:
            continue
        train_idx = np.asarray(train_indices, dtype=int)
        test_idx = np.asarray(test_indices, dtype=int)
        result = fit_predict(X, y, train_idx, test_idx, args.seed + 1000 + period_no, args.threshold)
        if result is None:
            continue
        train_students = {records[i]["student_id"] for i in train_indices}
        test_students = {records[i]["student_id"] for i in test_indices}
        overlap = train_students & test_students
        period_cases = []
        for local_i, global_i in enumerate(test_indices):
            case = audit_case(records[global_i], int(result["y_test"][local_i]),
                              int(result["prediction"][local_i]), float(result["probability"][local_i]),
                              args.threshold, args.include_student_id)
            period_cases.append(case)
            all_cases.append(case)
        bundle = dict(result["metrics"])
        bundle.update({
            "period_no": period_no, "academic_year_id": year_id, "bimester": base,
            "target_bimester": target, "cutoff_date": cutoff.isoformat(),
            "train_rows": int(len(train_idx)), "test_rows": int(len(test_idx)),
            "train_students": len(train_students), "test_students": len(test_students),
            "student_overlap": len(overlap),
            "student_overlap_rate": float(len(overlap)/len(test_students)) if test_students else 0.0,
            "test_positive_rate": float(np.mean(y[test_idx])),
            "case_summary": cases_summary(period_cases),
        })
        evaluations.append(bundle)

    names = ["accuracy","balanced_accuracy","precision","recall","f1","roc_auc","pr_auc"]
    summary = {name: summarize([m.get(name) for m in evaluations]) for name in names}
    latest = evaluations[-1] if evaluations else None
    latest_cases = []
    if latest is not None:
        latest_cases = [c for c in all_cases
                        if c["academic_year_id"] == latest["academic_year_id"]
                        and c["bimester"] == latest["bimester"]
                        and c["target_bimester"] == latest["target_bimester"]
                        and c["cutoff_date"] == latest["cutoff_date"]]
    baseline = None
    if latest is not None and latest_cases:
        actual = np.asarray([c["actual"] for c in latest_cases], dtype=int)
        always_positive = np.ones_like(actual)
        baseline = {
            "strategy": "marcar todos como riesgo",
            "accuracy": float(accuracy_score(actual, always_positive)),
            "balanced_accuracy": float(balanced_accuracy_score(actual, always_positive)),
            "precision": float(precision_score(actual, always_positive, zero_division=0)),
            "recall": float(recall_score(actual, always_positive, zero_division=0)),
            "f1": float(f1_score(actual, always_positive, zero_division=0)),
            "positive_rate": float(np.mean(actual)),
        }
    return {
        "method": "Walk-forward temporal: cada periodo se evalúa entrenando solo con filas de cutoff_date anterior",
        "periods_total": len(periods), "periods_evaluated": len(evaluations),
        "metrics_summary": summary, "periods": evaluations, "latest_holdout": latest,
        "latest_holdout_baseline": baseline,
        "error_audit": {
            "all_temporal_test_cases": cases_summary(all_cases),
            "selected_cases": select_cases(all_cases, args.max_cases),
            "latest_holdout_cases": select_cases(latest_cases, args.max_cases),
        },
    }


def main():
    args = args_parser()
    records, X_full, y, groups = load(Path(args.input))
    students = len(np.unique(groups))
    positive_rate = float(np.mean(y))
    missing_rates = {f: float(np.mean(np.isnan(X_full[:,i]))) for i,f in enumerate(FEATURES)}
    active_idx = [i for i in range(len(FEATURES)) if not np.all(np.isnan(X_full[:,i]))]
    active_features = [FEATURES[i] for i in active_idx]
    X = X_full[:,active_idx]

    grouped = grouped_validation(records, X, y, groups, args)
    temporal = temporal_validation(records, X, y, args)

    model_path = Path(args.model)
    model_info = None
    if model_path.exists():
        try:
            model_info = json.loads(model_path.read_text(encoding="utf-8"))
        except Exception:
            model_info = {"error": "No se pudo leer el JSON del modelo."}

    warnings = []
    blockers = []
    cutoff_sources = count_by(records, "cutoff_source")
    if len(y) < 100:
        warnings.append("Menos de 100 observaciones históricas: reportar métricas con cautela.")
    if students < 30:
        warnings.append("Menos de 30 estudiantes distintos: la estimación de generalización es inestable.")
    if positive_rate < 0.10 or positive_rate > 0.90:
        warnings.append("La variable objetivo está fuertemente desbalanceada.")
    if missing_rates["attendance_rate_30d"] > 0.50:
        warnings.append("Más del 50% de las observaciones no tiene asistencia suficiente.")
    if any("estimated" in source.lower() for source in cutoff_sources):
        blockers.append("Hay fechas de corte estimadas. Para la tesis final reexporta con cierres/fechas reales.")
    invalid_sources = [source for source in cutoff_sources
                       if not source.startswith("closure:") and "estimated" not in source.lower()]
    if invalid_sources:
        blockers.append("El dataset contiene fuentes temporales anteriores a la política de cierres: " + ", ".join(invalid_sources))

    grouped_summary = grouped["metrics_summary"]
    if grouped_summary["recall"]["mean"] is not None and grouped_summary["recall"]["mean"] < 0.60:
        warnings.append("Recall medio agrupado menor a 0.60: se pueden perder demasiados casos reales.")
    if grouped_summary["f1"]["mean"] is not None and grouped_summary["f1"]["mean"] < 0.55:
        warnings.append("F1 medio agrupado menor a 0.55: revisar modelo antes del piloto.")

    latest = temporal["latest_holdout"]
    if temporal["periods_evaluated"] == 0:
        warnings.append("No fue posible realizar validación temporal walk-forward con ambas clases.")
    elif latest is not None:
        if latest.get("test_rows", 0) < 30:
            warnings.append("El último holdout temporal tiene menos de 30 observaciones.")
        if latest.get("recall") is not None and latest["recall"] < 0.60:
            warnings.append("Recall del último holdout temporal menor a 0.60.")
        if latest.get("f1") is not None and latest["f1"] < 0.55:
            warnings.append("F1 del último holdout temporal menor a 0.55.")
        if latest.get("roc_auc") is not None and latest["roc_auc"] < 0.70:
            warnings.append("ROC-AUC del último holdout temporal menor a 0.70.")

    if isinstance(model_info, dict):
        if int(model_info.get("schema_version", 0) or 0) < 3:
            blockers.append("risk_model.json es anterior al esquema temporal v3 y debe reentrenarse.")
        if (model_info.get("training") or {}).get("temporal_policy") != "closed_N_to_closed_N_plus_1":
            blockers.append("El modelo no declara la política temporal de bimestres cerrados.")
        threshold = ((model_info.get("target_definition") or {}).get("numeric_threshold"))
        if threshold is not None and abs(float(threshold)-10.5) > 1e-9:
            blockers.append("El modelo no usa el criterio crítico <10.5 esperado.")

    status = "NO_APTO_PARA_MAIN" if blockers else ("REVISAR" if warnings else "APTO_PARA_PILOTO")
    report = {
        "status": status,
        "dataset": {
            "rows": int(len(y)), "students": int(students),
            "positive_rows": int(np.sum(y==1)), "negative_rows": int(np.sum(y==0)),
            "positive_rate": positive_rate, "features_used_for_validation": active_features,
            "missing_rate_by_feature": missing_rates, "cutoff_sources": cutoff_sources,
            "academic_years": count_by(records, "academic_year_id"),
            "bimester_pairs": pair_counts(records),
        },
        "validation": {"grouped_by_student": grouped, "temporal_walk_forward": temporal},
        "model_artifact": {
            "path": str(model_path), "available": model_path.exists(),
            "schema_version": model_info.get("schema_version") if isinstance(model_info,dict) else None,
            "created_at": model_info.get("created_at") if isinstance(model_info,dict) else None,
            "temporal_policy": ((model_info.get("training") or {}).get("temporal_policy")) if isinstance(model_info,dict) else None,
        },
        "blockers": blockers, "warnings": warnings,
        "interpretation": [
            "La validación agrupada por student_id mide generalización a estudiantes no vistos.",
            "La validación temporal mide desempeño futuro usando exclusivamente información anterior al periodo evaluado.",
            "El solapamiento de estudiantes entre train y test temporal es esperable: simula predecir periodos futuros de estudiantes ya matriculados.",
            "El baseline temporal muestra qué ocurriría si se marcara a todos como riesgo; balanced_accuracy=0.5 ayuda a detectar modelos triviales.",
            "Los falsos negativos son prioritarios porque representan estudiantes que entraron en riesgo y no fueron alertados.",
            "Los student_key están anonimizados salvo que se use --include-student-id para auditoría local.",
        ],
    }

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"Reporte creado: {output}")
    print(f"Estado: {report['status']}")
    print(f"Filas: {len(y)} | estudiantes: {students} | riesgo real: {positive_rate:.1%}")
    print("Pares cerrados: " + json.dumps(report["dataset"]["bimester_pairs"], ensure_ascii=False))
    print(f"Splits agrupados válidos: {grouped['valid_splits']}/{args.splits}")
    for name in ["recall","precision","f1","balanced_accuracy","roc_auc","pr_auc"]:
        stats = grouped["metrics_summary"][name]
        if stats["mean"] is not None:
            print(f"agrupado_{name}: {stats['mean']:.3f} ± {stats['std']:.3f}")

    print(f"Periodos temporales evaluados: {temporal['periods_evaluated']}/{temporal['periods_total']}")
    latest = temporal["latest_holdout"]
    if latest:
        print("Último holdout temporal: "
              f"año {latest['academic_year_id']} | {latest['bimester']}->{latest['target_bimester']} | "
              f"corte {latest['cutoff_date']} | train {latest['train_rows']} | test {latest['test_rows']}")
        for name in ["recall","precision","f1","balanced_accuracy","roc_auc","pr_auc"]:
            value = latest.get(name)
            if value is not None:
                print(f"temporal_{name}: {value:.3f}")
        cm = latest.get("confusion_matrix")
        if cm:
            tn, fp = cm[0]
            fn, tp = cm[1]
            print(f"Temporal TP={tp} | TN={tn} | FP={fp} | FN={fn}")

    audit = temporal["error_audit"]["all_temporal_test_cases"]["count_by_type"]
    print("Auditoría temporal de casos: " + json.dumps(audit, ensure_ascii=False))
    if blockers:
        print("BLOQUEADORES:")
        for item in blockers:
            print(f"- {item}")
    if warnings:
        print("ADVERTENCIAS:")
        for item in warnings:
            print(f"- {item}")

if __name__ == "__main__":
    main()
