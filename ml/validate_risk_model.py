#!/usr/bin/env python3
"""Auditoría metodológica v4 del modelo de alerta temprana EduSync.

Valida generalización por estudiante, desempeño walk-forward temporal y
calibración probabilística (Brier, ECE y bins de calibración). Cada modelo de
prueba calibra sus probabilidades usando únicamente el conjunto de entrenamiento
mediante Platt scaling sobre logits OOF agrupados por student_id.
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
    brier_score_loss,
    confusion_matrix,
    f1_score,
    precision_score,
    recall_score,
    roc_auc_score,
)
from sklearn.model_selection import GroupKFold, GroupShuffleSplit
from sklearn.preprocessing import StandardScaler

FEATURES = [
    "grade_mean_current",
    "grade_trend",
    "previous_bimester_available",
    "critical_records_current",
    "critical_courses_current",
    "attendance_rate_30d",
    "late_30d",
    "absent_30d",
]
TARGET = "target_next_bimester_risk"


def args_parser():
    p = argparse.ArgumentParser(description="Valida el dataset/modelo de riesgo académico EduSync v4")
    p.add_argument("--input", required=True)
    p.add_argument("--model", default="edusync/storage/ai_models/risk_model.json")
    p.add_argument("--output", default="storage/risk_validation_report.json")
    p.add_argument("--splits", type=int, default=10)
    p.add_argument("--test-size", type=float, default=0.25)
    p.add_argument("--seed", type=int, default=42)
    p.add_argument("--threshold", type=float, default=0.50)
    p.add_argument("--calibration-folds", type=int, default=5)
    p.add_argument("--max-cases", type=int, default=15)
    p.add_argument("--include-student-id", action="store_true")
    return p.parse_args()


def num(value):
    text = "" if value is None else str(value).strip().replace(",", ".")
    return float("nan") if text == "" else float(text)


def parse_date(value):
    try:
        return date.fromisoformat(str(value or "").strip())
    except ValueError:
        return None


def anonymize_student(student_id):
    return "S-" + hashlib.sha256(str(student_id).encode("utf-8")).hexdigest()[:10]


def load(path: Path):
    records = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        fields = set(reader.fieldnames or [])
        required = [
            "student_id", "academic_year_id", "bimester", "target_bimester",
            "cutoff_date", "cutoff_source", TARGET, *FEATURES,
        ]
        missing = [x for x in required if x not in fields]
        if missing:
            raise SystemExit(
                "Faltan columnas del esquema v4: " + ", ".join(missing) +
                ". Reexporta el dataset antes de validar."
            )
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
            if y not in (0, 1) or not student or target != base + 1 or cutoff is None:
                continue
            if not all(math.isfinite(v) for v in x[:5]):
                continue
            records.append({
                "x": x, "y": y, "student_id": student, "academic_year_id": year_id,
                "bimester": base, "target_bimester": target, "cutoff_date": cutoff, "row": row,
            })
    if not records:
        raise SystemExit("No hay filas válidas.")
    X = np.asarray([r["x"] for r in records], dtype=float)
    y = np.asarray([r["y"] for r in records], dtype=int)
    groups = np.asarray([r["student_id"] for r in records])
    return records, X, y, groups


def sigmoid(values):
    values = np.asarray(values, dtype=float)
    out = np.empty_like(values, dtype=float)
    pos = values >= 0
    out[pos] = 1.0 / (1.0 + np.exp(-np.minimum(values[pos], 700.0)))
    expv = np.exp(np.maximum(values[~pos], -700.0))
    out[~pos] = expv / (1.0 + expv)
    return out


def calibration_report(y_true, probabilities, n_bins=10):
    y_true = np.asarray(y_true, dtype=int)
    probabilities = np.clip(np.asarray(probabilities, dtype=float), 0.0, 1.0)
    bins, ece = [], 0.0
    for i in range(n_bins):
        lo, hi = i / n_bins, (i + 1) / n_bins
        mask = (probabilities >= lo) & (probabilities < hi if i < n_bins - 1 else probabilities <= hi)
        count = int(np.sum(mask))
        if count == 0:
            bins.append({"lower": lo, "upper": hi, "count": 0, "mean_predicted": None, "observed_rate": None, "gap": None})
            continue
        mean_p = float(np.mean(probabilities[mask]))
        observed = float(np.mean(y_true[mask]))
        gap = abs(mean_p - observed)
        ece += (count / len(y_true)) * gap
        bins.append({"lower": lo, "upper": hi, "count": count, "mean_predicted": mean_p, "observed_rate": observed, "gap": gap})
    return {"brier_score": float(brier_score_loss(y_true, probabilities)), "ece": float(ece), "bins": bins}


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
    result.update(calibration_report(y_true, prob))
    return result, pred


def fit_base_model(X, y, seed):
    imputer = SimpleImputer(strategy="median")
    X_imp = imputer.fit_transform(X)
    scaler = StandardScaler()
    X_std = scaler.fit_transform(X_imp)
    model = LogisticRegression(class_weight="balanced", max_iter=3000, random_state=seed)
    model.fit(X_std, y)
    return imputer, scaler, model


def decision_with_pipeline(X, imputer, scaler, model):
    return model.decision_function(scaler.transform(imputer.transform(X)))


def fit_platt(raw_scores, y):
    calibrator = LogisticRegression(C=1e6, solver="lbfgs", max_iter=2000)
    calibrator.fit(np.asarray(raw_scores).reshape(-1, 1), y)
    return float(calibrator.coef_[0][0]), float(calibrator.intercept_[0])


def apply_platt(raw_scores, coefficient, intercept):
    return sigmoid(intercept + coefficient * np.asarray(raw_scores, dtype=float))


def grouped_oof_logits(X, y, groups, seed, requested_folds):
    unique_groups = np.unique(groups)
    folds = min(max(2, requested_folds), len(unique_groups))
    if folds < 2:
        return None
    oof = np.full(len(y), np.nan, dtype=float)
    valid_folds = 0
    splitter = GroupKFold(n_splits=folds)
    for fold_no, (train_idx, val_idx) in enumerate(splitter.split(X, y, groups=groups), start=1):
        if len(np.unique(y[train_idx])) < 2 or len(np.unique(y[val_idx])) < 2:
            continue
        imputer, scaler, model = fit_base_model(X[train_idx], y[train_idx], seed + fold_no)
        oof[val_idx] = decision_with_pipeline(X[val_idx], imputer, scaler, model)
        valid_folds += 1
    mask = np.isfinite(oof)
    if np.sum(mask) < 30 or len(np.unique(y[mask])) < 2 or valid_folds < 2:
        return None
    return oof, mask, valid_folds


def fit_calibrated_model(X, y, groups, seed, calibration_folds):
    oof_result = grouped_oof_logits(X, y, groups, seed, calibration_folds)
    if oof_result is None:
        return None
    oof, mask, valid_folds = oof_result
    coefficient, intercept = fit_platt(oof[mask], y[mask])
    if not math.isfinite(coefficient) or coefficient <= 0 or not math.isfinite(intercept):
        return None
    imputer, scaler, model = fit_base_model(X, y, seed)
    return {
        "imputer": imputer, "scaler": scaler, "model": model,
        "coefficient": coefficient, "intercept": intercept,
        "calibration_folds": valid_folds, "calibration_rows": int(np.sum(mask)),
    }


def fit_predict(X, y, groups, train_idx, test_idx, seed, threshold, calibration_folds):
    y_train, y_test = y[train_idx], y[test_idx]
    if len(np.unique(y_train)) < 2 or len(np.unique(y_test)) < 2:
        return None
    fitted = fit_calibrated_model(X[train_idx], y_train, groups[train_idx], seed, calibration_folds)
    if fitted is None:
        return None
    raw = decision_with_pipeline(X[test_idx], fitted["imputer"], fitted["scaler"], fitted["model"])
    raw_prob = sigmoid(raw)
    prob = apply_platt(raw, fitted["coefficient"], fitted["intercept"])
    bundle, pred = metrics(y_test, prob, threshold)
    bundle["uncalibrated"] = calibration_report(y_test, raw_prob)
    bundle["calibration_fit"] = {
        "method": "platt_grouped_oof",
        "coefficient": fitted["coefficient"], "intercept": fitted["intercept"],
        "folds": fitted["calibration_folds"], "oof_rows": fitted["calibration_rows"],
    }
    return {"metrics": bundle, "probability": prob, "prediction": pred, "y_test": y_test}


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
        "bimester": rec["bimester"], "target_bimester": rec["target_bimester"],
        "cutoff_date": rec["cutoff_date"].isoformat(),
        "nivel": row.get("nivel"), "grado": row.get("grado"), "seccion": row.get("seccion"),
        "actual": int(actual), "predicted": int(predicted), "probability": float(probability),
        "distance_to_threshold": float(abs(float(probability) - threshold)),
        "case_type": case_type(int(actual), int(predicted)),
        "attendance_available": str(row.get("attendance_records_30d", "")).strip() not in ("", "0", "0.0"),
        "grade_mean_previous_observed": None,
        "features": {},
    }
    prev = row.get("grade_mean_previous_observed")
    if prev is not None and str(prev).strip() != "":
        try: item["grade_mean_previous_observed"] = float(str(prev).replace(",", "."))
        except ValueError: pass
    if include_student_id:
        item["student_id"] = rec["student_id"]
    for feature in FEATURES:
        raw = row.get(feature)
        if raw is None or str(raw).strip() == "":
            item["features"][feature] = None
        else:
            try: item["features"][feature] = float(str(raw).replace(",", "."))
            except ValueError: item["features"][feature] = None
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
    return {
        "count_by_type": dict(sorted(Counter(c["case_type"] for c in cases).items())),
        "count_by_pair": dict(sorted(Counter(f"{c['bimester']}->{c['target_bimester']}" for c in cases).items())),
        "count_by_level": dict(sorted(Counter(str(c.get("nivel") or "sin_dato") for c in cases).items())),
        "false_negative_profile": feature_profile([c for c in cases if c["case_type"] == "falso_negativo"]),
        "false_positive_profile": feature_profile([c for c in cases if c["case_type"] == "falso_positivo"]),
    }


def select_cases(cases, max_cases):
    return {
        "true_positives": sorted([c for c in cases if c["case_type"] == "verdadero_positivo"], key=lambda c: -c["probability"])[:max_cases],
        "true_negatives": sorted([c for c in cases if c["case_type"] == "verdadero_negativo"], key=lambda c: c["probability"])[:max_cases],
        "false_positives": sorted([c for c in cases if c["case_type"] == "falso_positivo"], key=lambda c: -c["probability"])[:max_cases],
        "false_negatives": sorted([c for c in cases if c["case_type"] == "falso_negativo"], key=lambda c: c["probability"])[:max_cases],
    }


def grouped_validation(records, X, y, groups, args):
    splitter = GroupShuffleSplit(n_splits=max(2, args.splits), test_size=args.test_size, random_state=args.seed)
    split_metrics, reference = [], None
    for split_no, (train_idx, test_idx) in enumerate(splitter.split(X, y, groups=groups), start=1):
        result = fit_predict(X, y, groups, train_idx, test_idx, args.seed + split_no, args.threshold, args.calibration_folds)
        if result is None:
            continue
        bundle = dict(result["metrics"])
        bundle.update({
            "split": split_no, "train_rows": int(len(train_idx)), "test_rows": int(len(test_idx)),
            "test_students": int(len(np.unique(groups[test_idx]))),
        })
        split_metrics.append(bundle)
        if reference is None:
            cases = [
                audit_case(records[global_i], int(result["y_test"][local_i]), int(result["prediction"][local_i]),
                           float(result["probability"][local_i]), args.threshold, args.include_student_id)
                for local_i, global_i in enumerate(test_idx)
            ]
            reference = {"metrics": bundle, "case_summary": cases_summary(cases), "cases": select_cases(cases, args.max_cases)}
    if not split_metrics:
        raise SystemExit("Ningún split agrupado produjo una evaluación calibrada válida.")
    names = ["accuracy", "balanced_accuracy", "precision", "recall", "f1", "roc_auc", "pr_auc", "brier_score", "ece"]
    summary = {name: summarize([m.get(name) for m in split_metrics]) for name in names}
    return {
        "method": "Repeated GroupShuffleSplit por student_id + Platt OOF dentro de train",
        "requested_splits": int(args.splits), "valid_splits": int(len(split_metrics)),
        "test_size": float(args.test_size), "decision_threshold": float(args.threshold),
        "metrics_summary": summary, "splits": split_metrics, "reference_split": reference,
    }


def temporal_periods(records):
    groups = {}
    for idx, rec in enumerate(records):
        key = (rec["cutoff_date"], rec["academic_year_id"], rec["bimester"], rec["target_bimester"])
        groups.setdefault(key, []).append(idx)
    return sorted(groups.items(), key=lambda item: item[0])


def temporal_validation(records, X, y, groups, args):
    periods = temporal_periods(records)
    evaluations, all_cases = [], []
    for period_no, (key, test_indices) in enumerate(periods, start=1):
        cutoff, year_id, base, target = key
        train_indices = [idx for idx, rec in enumerate(records) if rec["cutoff_date"] < cutoff]
        if not train_indices:
            continue
        train_idx = np.asarray(train_indices, dtype=int)
        test_idx = np.asarray(test_indices, dtype=int)
        result = fit_predict(X, y, groups, train_idx, test_idx, args.seed + 1000 + period_no, args.threshold, args.calibration_folds)
        if result is None:
            continue
        train_students = {records[i]["student_id"] for i in train_indices}
        test_students = {records[i]["student_id"] for i in test_indices}
        period_cases = []
        for local_i, global_i in enumerate(test_indices):
            case = audit_case(records[global_i], int(result["y_test"][local_i]), int(result["prediction"][local_i]),
                              float(result["probability"][local_i]), args.threshold, args.include_student_id)
            period_cases.append(case)
            all_cases.append(case)
        bundle = dict(result["metrics"])
        train_prevalence = float(np.mean(y[train_idx]))
        climatology_prob = np.full(len(test_idx), train_prevalence, dtype=float)
        bundle.update({
            "period_no": period_no, "academic_year_id": year_id, "bimester": base,
            "target_bimester": target, "cutoff_date": cutoff.isoformat(),
            "train_rows": int(len(train_idx)), "test_rows": int(len(test_idx)),
            "train_students": len(train_students), "test_students": len(test_students),
            "student_overlap": len(train_students & test_students),
            "student_overlap_rate": float(len(train_students & test_students) / len(test_students)) if test_students else 0.0,
            "train_positive_rate": train_prevalence, "test_positive_rate": float(np.mean(y[test_idx])),
            "climatology_brier_score": float(brier_score_loss(y[test_idx], climatology_prob)),
            "case_summary": cases_summary(period_cases),
        })
        evaluations.append(bundle)

    names = ["accuracy", "balanced_accuracy", "precision", "recall", "f1", "roc_auc", "pr_auc", "brier_score", "ece"]
    summary = {name: summarize([m.get(name) for m in evaluations]) for name in names}
    latest = evaluations[-1] if evaluations else None
    latest_cases = []
    if latest:
        latest_cases = [
            c for c in all_cases
            if c["academic_year_id"] == latest["academic_year_id"]
            and c["bimester"] == latest["bimester"]
            and c["target_bimester"] == latest["target_bimester"]
            and c["cutoff_date"] == latest["cutoff_date"]
        ]
    baseline = None
    if latest and latest_cases:
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
            "climatology_probability_from_train": latest["train_positive_rate"],
            "climatology_brier_score": latest["climatology_brier_score"],
        }
    return {
        "method": "Walk-forward temporal + Platt OOF usando solo filas anteriores al cutoff evaluado",
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
    missing_rates = {f: float(np.mean(np.isnan(X_full[:, i]))) for i, f in enumerate(FEATURES)}
    active_idx = [i for i in range(len(FEATURES)) if not np.all(np.isnan(X_full[:, i]))]
    active_features = [FEATURES[i] for i in active_idx]
    if "previous_bimester_available" not in active_features:
        raise SystemExit("El dataset no contiene la bandera previous_bimester_available de v4.")
    X = X_full[:, active_idx]

    grouped = grouped_validation(records, X, y, groups, args)
    temporal = temporal_validation(records, X, y, groups, args)

    model_path = Path(args.model)
    model_info = None
    if model_path.exists():
        try: model_info = json.loads(model_path.read_text(encoding="utf-8"))
        except Exception: model_info = {"error": "No se pudo leer el JSON del modelo."}

    warnings, blockers = [], []
    cutoff_sources = count_by(records, "cutoff_source")
    if len(y) < 100: warnings.append("Menos de 100 observaciones históricas: reportar métricas con cautela.")
    if students < 30: warnings.append("Menos de 30 estudiantes distintos: generalización inestable.")
    if positive_rate < 0.10 or positive_rate > 0.90: warnings.append("La variable objetivo está fuertemente desbalanceada.")
    if missing_rates["attendance_rate_30d"] > 0.50: warnings.append("Más del 50% de las observaciones no tiene asistencia suficiente.")
    if any("estimated" in source.lower() for source in cutoff_sources):
        blockers.append("Hay fechas de corte estimadas; reexporta con cierres/fechas reales para la tesis final.")
    invalid_sources = [source for source in cutoff_sources if not source.startswith("closure:") and "estimated" not in source.lower()]
    if invalid_sources: blockers.append("Fuentes temporales anteriores a la política de cierres: " + ", ".join(invalid_sources))

    gs = grouped["metrics_summary"]
    if gs["recall"]["mean"] is not None and gs["recall"]["mean"] < 0.60: warnings.append("Recall medio agrupado menor a 0.60.")
    if gs["f1"]["mean"] is not None and gs["f1"]["mean"] < 0.55: warnings.append("F1 medio agrupado menor a 0.55.")
    if gs["ece"]["mean"] is not None and gs["ece"]["mean"] > 0.10: warnings.append("ECE medio agrupado mayor a 0.10: revisar calibración.")

    latest = temporal["latest_holdout"]
    if temporal["periods_evaluated"] == 0:
        warnings.append("No fue posible realizar validación temporal calibrada.")
    elif latest:
        if latest.get("test_rows", 0) < 30: warnings.append("El último holdout temporal tiene menos de 30 observaciones.")
        if latest.get("recall") is not None and latest["recall"] < 0.60: warnings.append("Recall del último holdout temporal menor a 0.60.")
        if latest.get("f1") is not None and latest["f1"] < 0.55: warnings.append("F1 del último holdout temporal menor a 0.55.")
        if latest.get("roc_auc") is not None and latest["roc_auc"] < 0.70: warnings.append("ROC-AUC del último holdout temporal menor a 0.70.")
        if latest.get("ece") is not None and latest["ece"] > 0.10: warnings.append("ECE del último holdout temporal mayor a 0.10.")
        if latest.get("brier_score") is not None and latest.get("climatology_brier_score") is not None and latest["brier_score"] >= latest["climatology_brier_score"]:
            warnings.append("El Brier del modelo no mejora al baseline de prevalencia histórica en el último holdout.")

    if isinstance(model_info, dict):
        if int(model_info.get("schema_version", 0) or 0) < 4: blockers.append("risk_model.json es anterior al esquema v4 y debe reentrenarse.")
        if (model_info.get("training") or {}).get("temporal_policy") != "closed_N_to_closed_N_plus_1": blockers.append("El modelo no declara la política temporal cerrada.")
        features = list(model_info.get("features") or [])
        if "grade_mean_previous" in features: blockers.append("El modelo conserva grade_mean_previous redundante; debe reentrenarse v4.")
        if "previous_bimester_available" not in features: blockers.append("El modelo no incluye previous_bimester_available.")
        calibration = model_info.get("calibration") or {}
        if calibration.get("method") != "platt_grouped_oof": blockers.append("El modelo no incluye calibración Platt OOF agrupada.")
        try:
            if float(calibration.get("coefficient", 0)) <= 0: blockers.append("El coeficiente de calibración no es válido.")
        except (TypeError, ValueError): blockers.append("El coeficiente de calibración no es válido.")
        threshold = ((model_info.get("target_definition") or {}).get("numeric_threshold"))
        if threshold is not None and abs(float(threshold) - 10.5) > 1e-9: blockers.append("El modelo no usa el criterio crítico <10.5 esperado.")

    status = "NO_APTO_PARA_MAIN" if blockers else ("REVISAR" if warnings else "APTO_PARA_PILOTO")
    report = {
        "status": status,
        "dataset": {
            "rows": int(len(y)), "students": int(students),
            "positive_rows": int(np.sum(y == 1)), "negative_rows": int(np.sum(y == 0)),
            "positive_rate": positive_rate, "features_used_for_validation": active_features,
            "missing_rate_by_feature": missing_rates, "cutoff_sources": cutoff_sources,
            "academic_years": count_by(records, "academic_year_id"), "bimester_pairs": pair_counts(records),
        },
        "validation": {"grouped_by_student": grouped, "temporal_walk_forward": temporal},
        "model_artifact": {
            "path": str(model_path), "available": model_path.exists(),
            "schema_version": model_info.get("schema_version") if isinstance(model_info, dict) else None,
            "created_at": model_info.get("created_at") if isinstance(model_info, dict) else None,
            "temporal_policy": ((model_info.get("training") or {}).get("temporal_policy")) if isinstance(model_info, dict) else None,
            "calibration": model_info.get("calibration") if isinstance(model_info, dict) else None,
            "features": model_info.get("features") if isinstance(model_info, dict) else None,
        },
        "blockers": blockers, "warnings": warnings,
        "interpretation": [
            "La validación agrupada mide generalización a estudiantes no vistos.",
            "La validación temporal usa únicamente información anterior al periodo evaluado.",
            "Brier mide error cuadrático de probabilidad; menor es mejor.",
            "ECE compara probabilidad media y frecuencia observada por rangos; cercano a 0 es mejor.",
            "Platt scaling corrige la interpretación probabilística de la regresión balanceada sin usar el conjunto de prueba.",
            "Los falsos negativos siguen siendo prioritarios para la revisión humana.",
        ],
    }

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"Reporte creado: {output}")
    print(f"Estado: {status}")
    print(f"Filas: {len(y)} | estudiantes: {students} | riesgo real: {positive_rate:.1%}")
    print("Pares cerrados: " + json.dumps(report["dataset"]["bimester_pairs"], ensure_ascii=False))
    print(f"Splits agrupados válidos: {grouped['valid_splits']}/{args.splits}")
    for name in ["recall", "precision", "f1", "balanced_accuracy", "roc_auc", "pr_auc", "brier_score", "ece"]:
        stats = grouped["metrics_summary"][name]
        if stats["mean"] is not None: print(f"agrupado_{name}: {stats['mean']:.3f} ± {stats['std']:.3f}")

    print(f"Periodos temporales evaluados: {temporal['periods_evaluated']}/{temporal['periods_total']}")
    if latest:
        print(f"Último holdout temporal: año {latest['academic_year_id']} | {latest['bimester']}->{latest['target_bimester']} | corte {latest['cutoff_date']} | train {latest['train_rows']} | test {latest['test_rows']}")
        for name in ["recall", "precision", "f1", "balanced_accuracy", "roc_auc", "pr_auc", "brier_score", "ece"]:
            value = latest.get(name)
            if value is not None: print(f"temporal_{name}: {value:.3f}")
        print(f"temporal_brier_baseline_prevalencia: {latest['climatology_brier_score']:.3f}")
        cm = latest.get("confusion_matrix", [[0,0],[0,0]])
        print(f"Temporal TP={cm[1][1]} | TN={cm[0][0]} | FP={cm[0][1]} | FN={cm[1][0]}")
        print("Bins de calibración del último holdout:")
        for b in latest.get("bins", []):
            if b["count"]:
                print(f"  {int(b['lower']*100):02d}-{int(b['upper']*100):02d}% | n={b['count']} | pred={b['mean_predicted']:.3f} | real={b['observed_rate']:.3f} | gap={b['gap']:.3f}")

    audit = temporal["error_audit"]["all_temporal_test_cases"]["count_by_type"]
    print("Auditoría temporal de casos: " + json.dumps(audit, ensure_ascii=False))
    if blockers:
        print("BLOQUEADORES:")
        for item in blockers: print(f"- {item}")
    if warnings:
        print("ADVERTENCIAS:")
        for item in warnings: print(f"- {item}")


if __name__ == "__main__":
    main()
