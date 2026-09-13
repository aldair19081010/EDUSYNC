#!/usr/bin/env python3
"""Auditoría metodológica del dataset y modelo de alerta temprana EduSync.

No publica datos ni usa nombres. Evalúa múltiples splits por student_id, revisa
balance, faltantes, fuentes temporales y genera ejemplos de falsos positivos y
falsos negativos para revisión manual de tesis.
"""

from __future__ import annotations

import argparse
import csv
import json
import math
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
    return p.parse_args()


def num(value):
    text = "" if value is None else str(value).strip().replace(",", ".")
    return float("nan") if text == "" else float(text)


def load(path: Path):
    records = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        fields = set(reader.fieldnames or [])
        missing = [x for x in ["student_id", TARGET, *FEATURES] if x not in fields]
        if missing:
            raise SystemExit("Faltan columnas: " + ", ".join(missing))
        for row in reader:
            try:
                x = [num(row.get(feature)) for feature in FEATURES]
                y = int(float(row[TARGET]))
                student = str(row["student_id"]).strip()
            except (ValueError, TypeError):
                continue
            if y not in (0, 1) or not student:
                continue
            if not all(math.isfinite(v) for v in x[:5]):
                continue
            records.append({"x": x, "y": y, "student_id": student, "row": row})
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
        "confusion_matrix": confusion_matrix(y_true, pred, labels=[0, 1]).tolist(),
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


def main():
    args = args_parser()
    records, X_full, y, groups = load(Path(args.input))
    students = len(np.unique(groups))
    positive_rate = float(np.mean(y))
    missing_rates = {f: float(np.mean(np.isnan(X_full[:, i]))) for i, f in enumerate(FEATURES)}

    active_idx = [i for i in range(len(FEATURES)) if not np.all(np.isnan(X_full[:, i]))]
    active_features = [FEATURES[i] for i in active_idx]
    X = X_full[:, active_idx]

    splitter = GroupShuffleSplit(n_splits=max(2, args.splits), test_size=args.test_size, random_state=args.seed)
    split_metrics = []
    reference = None
    valid_splits = 0
    for split_no, (train_idx, test_idx) in enumerate(splitter.split(X, y, groups=groups), start=1):
        y_train, y_test = y[train_idx], y[test_idx]
        if len(np.unique(y_train)) < 2 or len(np.unique(y_test)) < 2:
            continue
        imputer = SimpleImputer(strategy="median")
        X_train = imputer.fit_transform(X[train_idx])
        X_test = imputer.transform(X[test_idx])
        scaler = StandardScaler()
        X_train = scaler.fit_transform(X_train)
        X_test = scaler.transform(X_test)
        model = LogisticRegression(class_weight="balanced", max_iter=3000, random_state=args.seed + split_no)
        model.fit(X_train, y_train)
        prob = model.predict_proba(X_test)[:, 1]
        bundle, pred = metrics(y_test, prob, args.threshold)
        bundle["split"] = split_no
        bundle["train_rows"] = int(len(train_idx))
        bundle["test_rows"] = int(len(test_idx))
        bundle["test_students"] = int(len(np.unique(groups[test_idx])))
        split_metrics.append(bundle)
        valid_splits += 1
        if reference is None:
            errors = []
            for local_i, global_i in enumerate(test_idx):
                if int(pred[local_i]) == int(y_test[local_i]):
                    continue
                row = records[global_i]["row"]
                errors.append({
                    "student_id": records[global_i]["student_id"],
                    "academic_year_id": row.get("academic_year_id"),
                    "bimester": row.get("bimester"),
                    "cutoff_source": row.get("cutoff_source"),
                    "actual": int(y_test[local_i]),
                    "predicted": int(pred[local_i]),
                    "probability": float(prob[local_i]),
                    "error_type": "falso_positivo" if int(pred[local_i]) == 1 else "falso_negativo",
                })
            false_pos = sorted([e for e in errors if e["error_type"] == "falso_positivo"], key=lambda e: -e["probability"])[:10]
            false_neg = sorted([e for e in errors if e["error_type"] == "falso_negativo"], key=lambda e: e["probability"])[:10]
            reference = {"metrics": bundle, "false_positives": false_pos, "false_negatives": false_neg}

    if valid_splits == 0:
        raise SystemExit("Ningún split produjo ambas clases en train y test. El dataset todavía es insuficiente para validación estable.")

    metric_names = ["accuracy", "balanced_accuracy", "precision", "recall", "f1", "roc_auc", "pr_auc"]
    repeated = {name: summarize([m.get(name) for m in split_metrics]) for name in metric_names}

    model_info = None
    model_path = Path(args.model)
    if model_path.exists():
        try:
            model_info = json.loads(model_path.read_text(encoding="utf-8"))
        except Exception:
            model_info = {"error": "No se pudo leer el JSON del modelo."}

    warnings = []
    blockers = []
    if len(y) < 100:
        warnings.append("Menos de 100 observaciones históricas: reportar métricas con cautela.")
    if students < 30:
        warnings.append("Menos de 30 estudiantes distintos: la estimación de generalización es inestable.")
    if positive_rate < 0.10 or positive_rate > 0.90:
        warnings.append("La variable objetivo está fuertemente desbalanceada.")
    if missing_rates["attendance_rate_30d"] > 0.50:
        warnings.append("Más del 50% de las observaciones no tiene asistencia suficiente; el modelo depende principalmente de datos académicos.")
    cutoff_sources = count_by(records, "cutoff_source")
    if cutoff_sources.get("estimated_quarter", 0) > 0:
        blockers.append("Hay cutoffs estimados. Para la tesis final conviene reexportar usando fechas/cierres reales.")
    if repeated["recall"]["mean"] is not None and repeated["recall"]["mean"] < 0.60:
        warnings.append("Recall medio menor a 0.60: el modelo puede dejar pasar demasiados estudiantes realmente en riesgo.")
    if repeated["f1"]["mean"] is not None and repeated["f1"]["mean"] < 0.55:
        warnings.append("F1 medio menor a 0.55: revisar variables, volumen de datos y umbral antes de producción.")
    if model_info and isinstance(model_info, dict):
        if int(model_info.get("schema_version", 0) or 0) < 2:
            blockers.append("risk_model.json es anterior al esquema v2 y debe reentrenarse.")
        target_threshold = ((model_info.get("target_definition") or {}).get("numeric_threshold"))
        if target_threshold is not None and abs(float(target_threshold) - 10.5) > 1e-9:
            blockers.append("El modelo no usa el criterio crítico <10.5 esperado por esta validación.")

    report = {
        "status": "NO_APTO_PARA_MAIN" if blockers else ("REVISAR" if warnings else "APTO_PARA_PILOTO"),
        "dataset": {
            "rows": int(len(y)),
            "students": int(students),
            "positive_rows": int(np.sum(y == 1)),
            "negative_rows": int(np.sum(y == 0)),
            "positive_rate": positive_rate,
            "features_used_for_validation": active_features,
            "missing_rate_by_feature": missing_rates,
            "cutoff_sources": cutoff_sources,
            "academic_years": count_by(records, "academic_year_id"),
            "bimesters": count_by(records, "bimester"),
        },
        "validation": {
            "method": "Repeated GroupShuffleSplit por student_id",
            "requested_splits": int(args.splits),
            "valid_splits": int(valid_splits),
            "test_size": float(args.test_size),
            "decision_threshold": float(args.threshold),
            "metrics_summary": repeated,
            "splits": split_metrics,
            "reference_split": reference,
        },
        "model_artifact": {
            "path": str(model_path),
            "available": model_path.exists(),
            "schema_version": model_info.get("schema_version") if isinstance(model_info, dict) else None,
            "created_at": model_info.get("created_at") if isinstance(model_info, dict) else None,
            "features": model_info.get("features") if isinstance(model_info, dict) else None,
            "target_definition": model_info.get("target_definition") if isinstance(model_info, dict) else None,
        },
        "blockers": blockers,
        "warnings": warnings,
        "interpretation": [
            "Recall indica cuántos estudiantes que realmente entraron en riesgo fueron detectados.",
            "Precision indica cuántas alertas emitidas correspondieron a riesgo real.",
            "F1 equilibra precision y recall.",
            "PR-AUC es especialmente útil cuando la clase de riesgo es minoritaria.",
            "Los falsos positivos y falsos negativos deben revisarse manualmente antes del despliegue.",
        ],
    }

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"Reporte creado: {output}")
    print(f"Estado: {report['status']}")
    print(f"Filas: {len(y)} | estudiantes: {students} | riesgo real: {positive_rate:.1%}")
    print(f"Splits válidos: {valid_splits}/{args.splits}")
    for name in ["recall", "precision", "f1", "balanced_accuracy", "roc_auc", "pr_auc"]:
        stats = repeated[name]
        if stats["mean"] is not None:
            print(f"{name}: {stats['mean']:.3f} ± {stats['std']:.3f}")
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
