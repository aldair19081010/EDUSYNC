#!/usr/bin/env python3
"""Entrena el modelo longitudinal candidato v5 de EduSync.

Este artefacto NO sustituye por sí solo al risk_model.json v4 activo.
Usa trayectoria académica y de asistencia construida únicamente con periodos
cerrados disponibles hasta el bimestre base.
"""
from __future__ import annotations

import argparse
import csv
import json
import math
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    accuracy_score, average_precision_score, balanced_accuracy_score,
    brier_score_loss, confusion_matrix, f1_score, precision_score,
    recall_score, roc_auc_score,
)
from sklearn.model_selection import GroupKFold, GroupShuffleSplit
from sklearn.preprocessing import StandardScaler

FEATURES = [
    "grade_mean_current",
    "grade_trend_recent",
    "previous_period_available",
    "history_periods_available",
    "historical_years_available",
    "previous_academic_year_history",
    "historical_mean_prior",
    "long_term_grade_trend",
    "grade_volatility_recent",
    "critical_records_current",
    "critical_courses_current",
    "critical_courses_recent_3_mean",
    "critical_period_rate_prior",
    "attendance_rate_30d",
    "attendance_historical_mean",
    "attendance_trend_recent",
    "late_30d",
    "late_mean_recent_3",
    "absent_30d",
    "absent_mean_recent_3",
]
TARGET = "target_next_bimester_risk"


def parse_args():
    p = argparse.ArgumentParser(description="Entrena candidato longitudinal EduSync v5")
    p.add_argument("--input", required=True)
    p.add_argument("--output", default="edusync/storage/ai_models/risk_model_v5_candidate.json")
    p.add_argument("--test-size", type=float, default=0.25)
    p.add_argument("--seed", type=int, default=42)
    p.add_argument("--min-rows", type=int, default=80)
    p.add_argument("--medium-threshold", type=float, default=0.40)
    p.add_argument("--high-threshold", type=float, default=0.70)
    p.add_argument("--attendance-window", type=int, default=30)
    p.add_argument("--critical-threshold", type=float, default=10.5)
    p.add_argument("--calibration-folds", type=int, default=5)
    return p.parse_args()


def parse_float(value):
    text = "" if value is None else str(value).strip().replace(",", ".")
    return float("nan") if text == "" else float(text)


def load_dataset(path: Path):
    rows, raw_rows = [], []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        fields = set(reader.fieldnames or [])
        required = ["student_id", "academic_year_id", "bimester", "target_bimester",
                    "cutoff_date", "cutoff_source", TARGET, *FEATURES]
        missing = [c for c in required if c not in fields]
        if missing:
            raise SystemExit(
                "Faltan columnas longitudinales v5: " + ", ".join(missing) +
                ". Reexporta con tools/export_risk_dataset_v5.php."
            )
        for row in reader:
            try:
                x = [parse_float(row.get(feature)) for feature in FEATURES]
                y = int(float(str(row[TARGET]).strip()))
                group = str(row["student_id"]).strip()
                base = int(float(str(row["bimester"]).strip()))
                target = int(float(str(row["target_bimester"]).strip()))
            except (TypeError, ValueError):
                continue
            if y not in (0, 1) or not group or target != base + 1:
                continue
            rows.append((x, y, group))
            raw_rows.append(row)
    if not rows:
        raise SystemExit("No hay filas válidas para entrenar v5.")
    X = np.asarray([r[0] for r in rows], dtype=float)
    y = np.asarray([r[1] for r in rows], dtype=int)
    groups = np.asarray([r[2] for r in rows])
    return X, y, groups, raw_rows


def sigmoid(values):
    values = np.asarray(values, dtype=float)
    out = np.empty_like(values, dtype=float)
    pos = values >= 0
    out[pos] = 1.0 / (1.0 + np.exp(-np.minimum(values[pos], 700.0)))
    expv = np.exp(np.maximum(values[~pos], -700.0))
    out[~pos] = expv / (1.0 + expv)
    return out


def calibration_report(y_true, prob, n_bins=10):
    y_true = np.asarray(y_true, dtype=int)
    prob = np.clip(np.asarray(prob, dtype=float), 0.0, 1.0)
    bins, ece = [], 0.0
    for i in range(n_bins):
        lo, hi = i / n_bins, (i + 1) / n_bins
        mask = (prob >= lo) & (prob < hi if i < n_bins - 1 else prob <= hi)
        count = int(np.sum(mask))
        if not count:
            bins.append({"lower": lo, "upper": hi, "count": 0,
                         "mean_predicted": None, "observed_rate": None, "gap": None})
            continue
        mean_p = float(np.mean(prob[mask]))
        observed = float(np.mean(y_true[mask]))
        gap = abs(mean_p - observed)
        ece += (count / len(y_true)) * gap
        bins.append({"lower": lo, "upper": hi, "count": count,
                     "mean_predicted": mean_p, "observed_rate": observed, "gap": gap})
    return {"brier_score": float(brier_score_loss(y_true, prob)), "ece": float(ece), "bins": bins}


def metric_bundle(y_true, prob, threshold=0.5):
    pred = (prob >= threshold).astype(int)
    out = {
        "decision_threshold": float(threshold),
        "accuracy": float(accuracy_score(y_true, pred)),
        "balanced_accuracy": float(balanced_accuracy_score(y_true, pred)),
        "precision": float(precision_score(y_true, pred, zero_division=0)),
        "recall": float(recall_score(y_true, pred, zero_division=0)),
        "f1": float(f1_score(y_true, pred, zero_division=0)),
        "confusion_matrix": confusion_matrix(y_true, pred, labels=[0, 1]).tolist(),
        "roc_auc": float(roc_auc_score(y_true, prob)) if len(np.unique(y_true)) == 2 else None,
        "pr_auc": float(average_precision_score(y_true, prob)) if len(np.unique(y_true)) == 2 else None,
    }
    out.update(calibration_report(y_true, prob))
    return out


def fit_base(X, y, seed):
    imputer = SimpleImputer(strategy="median")
    X_imp = imputer.fit_transform(X)
    scaler = StandardScaler()
    X_std = scaler.fit_transform(X_imp)
    model = LogisticRegression(class_weight="balanced", max_iter=3000, random_state=seed)
    model.fit(X_std, y)
    return imputer, scaler, model


def decision(X, imputer, scaler, model):
    return model.decision_function(scaler.transform(imputer.transform(X)))


def grouped_oof_logits(X, y, groups, seed, requested_folds):
    unique_groups = np.unique(groups)
    folds = min(max(2, requested_folds), len(unique_groups))
    oof = np.full(len(y), np.nan, dtype=float)
    valid = 0
    for fold_no, (tr, va) in enumerate(GroupKFold(n_splits=folds).split(X, y, groups=groups), start=1):
        if len(np.unique(y[tr])) < 2 or len(np.unique(y[va])) < 2:
            continue
        imputer, scaler, model = fit_base(X[tr], y[tr], seed + fold_no)
        oof[va] = decision(X[va], imputer, scaler, model)
        valid += 1
    mask = np.isfinite(oof)
    if int(np.sum(mask)) < 30 or valid < 2 or len(np.unique(y[mask])) < 2:
        raise SystemExit("No fue posible construir logits OOF suficientes para calibrar v5.")
    return oof, mask, valid


def fit_platt(raw_scores, y):
    calibrator = LogisticRegression(C=1e6, solver="lbfgs", max_iter=2000)
    calibrator.fit(np.asarray(raw_scores).reshape(-1, 1), y)
    coef = float(calibrator.coef_[0][0])
    intercept = float(calibrator.intercept_[0])
    if not math.isfinite(coef) or coef <= 0 or not math.isfinite(intercept):
        raise SystemExit("La calibración v5 produjo parámetros no válidos.")
    return coef, intercept


def apply_platt(raw_scores, coef, intercept):
    return sigmoid(intercept + coef * np.asarray(raw_scores, dtype=float))


def fit_calibrated(X, y, groups, seed, folds):
    oof, mask, valid = grouped_oof_logits(X, y, groups, seed, folds)
    coef, intercept = fit_platt(oof[mask], y[mask])
    imputer, scaler, model = fit_base(X, y, seed)
    return {
        "imputer": imputer,
        "scaler": scaler,
        "model": model,
        "calibration_coefficient": coef,
        "calibration_intercept": intercept,
        "calibration_folds": valid,
        "calibration_oof_rows": int(np.sum(mask)),
        "calibration_oof_report": calibration_report(y[mask], apply_platt(oof[mask], coef, intercept)),
    }


def main():
    args = parse_args()
    X_full, y, groups, raw_rows = load_dataset(Path(args.input))
    if len(y) < args.min_rows:
        raise SystemExit(f"Dataset insuficiente: {len(y)} filas. Mínimo: {args.min_rows}.")
    if len(np.unique(y)) < 2:
        raise SystemExit("La variable objetivo solo contiene una clase.")
    if len(np.unique(groups)) < 8:
        raise SystemExit("Hay muy pocos estudiantes distintos.")

    active_idx = [i for i in range(len(FEATURES)) if not np.all(np.isnan(X_full[:, i]))]
    features = [FEATURES[i] for i in active_idx]
    dropped = [f for i, f in enumerate(FEATURES) if i not in active_idx]
    X = X_full[:, active_idx]

    splitter = GroupShuffleSplit(n_splits=1, test_size=args.test_size, random_state=args.seed)
    train_idx, test_idx = next(splitter.split(X, y, groups=groups))
    if len(np.unique(y[train_idx])) < 2 or len(np.unique(y[test_idx])) < 2:
        raise SystemExit("El holdout por estudiante dejó una sola clase.")

    holdout_fit = fit_calibrated(X[train_idx], y[train_idx], groups[train_idx], args.seed, args.calibration_folds)
    raw_test = decision(X[test_idx], holdout_fit["imputer"], holdout_fit["scaler"], holdout_fit["model"])
    prob_test = apply_platt(raw_test, holdout_fit["calibration_coefficient"], holdout_fit["calibration_intercept"])
    holdout_metrics = metric_bundle(y[test_idx], prob_test)

    final_fit = fit_calibrated(X, y, groups, args.seed + 5000, args.calibration_folds)
    imputer, scaler, model = final_fit["imputer"], final_fit["scaler"], final_fit["model"]

    pair_counts = {}
    source_counts = {}
    for row in raw_rows:
        pair = f"{row.get('bimester')}->{row.get('target_bimester')}"
        pair_counts[pair] = pair_counts.get(pair, 0) + 1
        source = str(row.get("cutoff_source") or "sin_fuente")
        source_counts[source] = source_counts.get(source, 0) + 1
    missing_rates = {f: float(np.mean(np.isnan(X_full[:, i]))) for i, f in enumerate(FEATURES)}

    artifact = {
        "schema_version": 5,
        "model_variant": "longitudinal_v5_candidate",
        "deployment_status": "candidate_not_active",
        "model_type": "logistic_regression",
        "created_at": datetime.now(timezone.utc).isoformat(),
        "target": "al_menos_un_curso_critico_en_el_bimestre_siguiente_cerrado",
        "target_definition": {
            "letter": "C",
            "numeric_rule": f"nota < {args.critical_threshold:g}",
            "numeric_threshold": float(args.critical_threshold),
            "temporal_rule": "historia cerrada disponible hasta N -> resultado observado al cierre de N+1",
        },
        "longitudinal_policy": {
            "cross_academic_years": True,
            "current_period_required": True,
            "history_only_from_closed_periods": True,
            "recent_grade_window_periods": 4,
            "recent_critical_window_periods": 3,
            "historical_attendance_window_periods": 3,
            "note": "Para I bimestre puede usar IV y otros periodos de años anteriores si existen y están cerrados.",
        },
        "features": features,
        "dropped_all_missing_features": dropped,
        "imputer": {"strategy": "median", "fill": {f: float(imputer.statistics_[i]) for i, f in enumerate(features)}},
        "scaler": {
            "mean": {f: float(scaler.mean_[i]) for i, f in enumerate(features)},
            "scale": {f: float(scaler.scale_[i] if abs(scaler.scale_[i]) > 1e-12 else 1.0) for i, f in enumerate(features)},
        },
        "coefficients": {f: float(model.coef_[0][i]) for i, f in enumerate(features)},
        "intercept": float(model.intercept_[0]),
        "calibration": {
            "method": "platt_grouped_oof",
            "coefficient": float(final_fit["calibration_coefficient"]),
            "intercept": float(final_fit["calibration_intercept"]),
            "group_folds": int(final_fit["calibration_folds"]),
            "oof_rows": int(final_fit["calibration_oof_rows"]),
            "oof_report": final_fit["calibration_oof_report"],
        },
        "risk_thresholds": {"medium": float(args.medium_threshold), "high": float(args.high_threshold), "probability_space": "calibrated"},
        "metrics": {"holdout_grouped_student": holdout_metrics},
        "training": {
            "rows": int(len(y)),
            "students": int(len(np.unique(groups))),
            "positive_rate": float(np.mean(y)),
            "attendance_window_days": int(args.attendance_window),
            "temporal_policy": "closed_history_through_N_to_closed_N_plus_1",
            "split": "GroupShuffleSplit por student_id",
            "cutoff_source_counts": source_counts,
            "bimester_pair_counts": pair_counts,
            "missing_rate_by_feature": missing_rates,
        },
        "warnings": [
            "Este artefacto es candidato y no sustituye automáticamente al modelo v4 activo.",
            "Debe compararse contra v4 con validación agrupada y walk-forward temporal.",
            "La probabilidad es una alerta de apoyo, no una decisión automática.",
        ],
    }

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(artifact, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"Modelo candidato exportado: {output}")
    print("Esquema: v5 longitudinal | estado: candidate_not_active")
    print(f"Filas: {len(y)} | estudiantes: {len(np.unique(groups))} | riesgo real: {np.mean(y):.1%}")
    print("Variables usadas: " + ", ".join(features))
    if dropped:
        print("Variables 100% vacías excluidas: " + ", ".join(dropped))
    print("Holdout agrupado del candidato:")
    for name in ["recall", "precision", "f1", "balanced_accuracy", "roc_auc", "pr_auc", "brier_score", "ece"]:
        value = holdout_metrics.get(name)
        if value is not None:
            print(f"v5_{name}: {value:.3f}")


if __name__ == "__main__":
    main()
