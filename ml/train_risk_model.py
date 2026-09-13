#!/usr/bin/env python3
"""Entrena y exporta el modelo predictivo de riesgo académico de EduSync v4.

Política metodológica:
- pares históricos cerrados N -> N+1;
- split de evaluación por student_id;
- grade_mean_previous no se usa como predictor para evitar redundancia exacta
  con grade_mean_current y grade_trend;
- previous_bimester_available distingue "sin historial previo" de tendencia 0;
- la regresión logística balanceada se calibra con Platt scaling aprendido
  sobre logits fuera de muestra por estudiante (GroupKFold).
"""
from __future__ import annotations

import argparse
import csv
import json
import math
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from sklearn.ensemble import RandomForestClassifier
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


def parse_args():
    p = argparse.ArgumentParser(description="Entrena el modelo predictivo de riesgo EduSync v4")
    p.add_argument("--input", required=True)
    p.add_argument("--output", default="edusync/storage/ai_models/risk_model.json")
    p.add_argument("--test-size", type=float, default=0.25)
    p.add_argument("--seed", type=int, default=42)
    p.add_argument("--min-rows", type=int, default=40)
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
        required = ["student_id", "bimester", "target_bimester", "cutoff_source", TARGET, *FEATURES]
        missing = [c for c in required if c not in fields]
        if missing:
            raise SystemExit(
                "Faltan columnas del esquema v4: " + ", ".join(missing) +
                ". Reexporta el dataset con tools/export_risk_dataset.php."
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
            if not all(math.isfinite(v) for v in x[:5]):
                continue
            rows.append((x, y, group))
            raw_rows.append(row)
    if not rows:
        raise SystemExit("No hay filas válidas para entrenar.")
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


def calibration_report(y_true, probabilities, n_bins=10):
    y_true = np.asarray(y_true, dtype=int)
    probabilities = np.clip(np.asarray(probabilities, dtype=float), 0.0, 1.0)
    bins = []
    ece = 0.0
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
    return {
        "brier_score": float(brier_score_loss(y_true, probabilities)),
        "ece": float(ece),
        "bins": bins,
    }


def metric_bundle(y_true, probabilities, threshold=0.5):
    pred = (probabilities >= threshold).astype(int)
    out = {
        "decision_threshold": float(threshold),
        "accuracy": float(accuracy_score(y_true, pred)),
        "balanced_accuracy": float(balanced_accuracy_score(y_true, pred)),
        "precision": float(precision_score(y_true, pred, zero_division=0)),
        "recall": float(recall_score(y_true, pred, zero_division=0)),
        "f1": float(f1_score(y_true, pred, zero_division=0)),
        "confusion_matrix": confusion_matrix(y_true, pred, labels=[0, 1]).tolist(),
    }
    if len(np.unique(y_true)) == 2:
        out["roc_auc"] = float(roc_auc_score(y_true, probabilities))
        out["pr_auc"] = float(average_precision_score(y_true, probabilities))
    else:
        out["roc_auc"] = None
        out["pr_auc"] = None
    out.update(calibration_report(y_true, probabilities))
    return out


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
    oof = np.full(len(y), np.nan, dtype=float)
    splitter = GroupKFold(n_splits=folds)
    valid_folds = 0
    for fold_no, (train_idx, val_idx) in enumerate(splitter.split(X, y, groups=groups), start=1):
        if len(np.unique(y[train_idx])) < 2 or len(np.unique(y[val_idx])) < 2:
            continue
        imputer, scaler, model = fit_base_model(X[train_idx], y[train_idx], seed + fold_no)
        oof[val_idx] = decision_with_pipeline(X[val_idx], imputer, scaler, model)
        valid_folds += 1
    mask = np.isfinite(oof)
    if np.sum(mask) < 30 or len(np.unique(y[mask])) < 2 or valid_folds < 2:
        raise SystemExit("No fue posible construir suficientes logits OOF por estudiante para calibrar el modelo.")
    return oof, mask, valid_folds


def fit_calibrated_model(X, y, groups, seed, calibration_folds):
    oof, mask, valid_folds = grouped_oof_logits(X, y, groups, seed, calibration_folds)
    coefficient, intercept = fit_platt(oof[mask], y[mask])
    if not math.isfinite(coefficient) or coefficient <= 0 or not math.isfinite(intercept):
        raise SystemExit("La calibración produjo parámetros no válidos; revisa el dataset.")
    imputer, scaler, model = fit_base_model(X, y, seed)
    oof_calibrated = apply_platt(oof[mask], coefficient, intercept)
    return {
        "imputer": imputer,
        "scaler": scaler,
        "model": model,
        "calibration_coefficient": coefficient,
        "calibration_intercept": intercept,
        "calibration_folds": valid_folds,
        "calibration_oof_rows": int(np.sum(mask)),
        "calibration_oof_report": calibration_report(y[mask], oof_calibrated),
    }


def missing_rates(X, features):
    return {feature: float(np.mean(np.isnan(X[:, i]))) for i, feature in enumerate(features)}


def main():
    args = parse_args()
    X_full, y, groups, raw_rows = load_dataset(Path(args.input))
    if len(y) < args.min_rows:
        raise SystemExit(f"Dataset insuficiente: {len(y)} filas. Mínimo: {args.min_rows}.")
    if len(np.unique(y)) < 2:
        raise SystemExit("La variable objetivo solo contiene una clase.")
    if len(np.unique(groups)) < 8:
        raise SystemExit("Hay muy pocos estudiantes distintos para una evaluación por grupos confiable.")

    active_indexes = [i for i in range(len(FEATURES)) if not np.all(np.isnan(X_full[:, i]))]
    active_features = [FEATURES[i] for i in active_indexes]
    dropped_features = [f for i, f in enumerate(FEATURES) if i not in active_indexes]
    X = X_full[:, active_indexes]
    if "previous_bimester_available" not in active_features:
        raise SystemExit("Falta previous_bimester_available; reexporta el dataset v4.")
    if len(active_features) < 5:
        raise SystemExit("Demasiadas variables están completamente vacías; revisa el dataset.")

    splitter = GroupShuffleSplit(n_splits=1, test_size=args.test_size, random_state=args.seed)
    train_idx, test_idx = next(splitter.split(X, y, groups=groups))
    if len(np.unique(y[train_idx])) < 2 or len(np.unique(y[test_idx])) < 2:
        raise SystemExit("El split por estudiantes dejó una sola clase en train o test.")

    holdout_fit = fit_calibrated_model(
        X[train_idx], y[train_idx], groups[train_idx], args.seed, args.calibration_folds
    )
    raw_test = decision_with_pipeline(
        X[test_idx], holdout_fit["imputer"], holdout_fit["scaler"], holdout_fit["model"]
    )
    uncalibrated_prob = sigmoid(raw_test)
    calibrated_prob = apply_platt(
        raw_test, holdout_fit["calibration_coefficient"], holdout_fit["calibration_intercept"]
    )
    logistic_metrics = metric_bundle(y[test_idx], calibrated_prob)
    logistic_metrics["uncalibrated"] = calibration_report(y[test_idx], uncalibrated_prob)

    bench_imputer = SimpleImputer(strategy="median")
    X_train_imp = bench_imputer.fit_transform(X[train_idx])
    X_test_imp = bench_imputer.transform(X[test_idx])
    forest = RandomForestClassifier(
        n_estimators=300, min_samples_leaf=2, class_weight="balanced",
        random_state=args.seed, n_jobs=-1
    )
    forest.fit(X_train_imp, y[train_idx])
    forest_prob = forest.predict_proba(X_test_imp)[:, 1]
    forest_metrics = metric_bundle(y[test_idx], forest_prob)

    final_fit = fit_calibrated_model(X, y, groups, args.seed + 5000, args.calibration_folds)
    final_imputer = final_fit["imputer"]
    final_scaler = final_fit["scaler"]
    final_model = final_fit["model"]

    source_counts, bimester_counts, pair_counts = {}, {}, {}
    for row in raw_rows:
        source = str(row.get("cutoff_source", "") or "sin_fuente")
        source_counts[source] = source_counts.get(source, 0) + 1
        base = str(row.get("bimester", "") or "?")
        target = str(row.get("target_bimester", "") or "?")
        bimester_counts[base] = bimester_counts.get(base, 0) + 1
        pair = f"{base}->{target}"
        pair_counts[pair] = pair_counts.get(pair, 0) + 1

    full_missing = missing_rates(X_full, FEATURES)
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    artifact = {
        "schema_version": 4,
        "model_type": "logistic_regression",
        "created_at": datetime.now(timezone.utc).isoformat(),
        "target": "al_menos_un_curso_critico_en_el_bimestre_siguiente_cerrado",
        "target_definition": {
            "letter": "C",
            "numeric_rule": f"nota < {args.critical_threshold:g}",
            "numeric_threshold": float(args.critical_threshold),
            "temporal_rule": "features al cierre del bimestre N; resultado observado al cierre del bimestre N+1",
        },
        "feature_policy": {
            "removed_redundant_feature": "grade_mean_previous",
            "reason": "grade_trend ya codifica la diferencia; previous_bimester_available distingue ausencia de historial de tendencia realmente estable",
        },
        "features": active_features,
        "imputer": {
            "strategy": "median",
            "fill": {f: float(final_imputer.statistics_[i]) for i, f in enumerate(active_features)},
        },
        "scaler": {
            "mean": {f: float(final_scaler.mean_[i]) for i, f in enumerate(active_features)},
            "scale": {f: float(final_scaler.scale_[i] if abs(final_scaler.scale_[i]) > 1e-12 else 1.0) for i, f in enumerate(active_features)},
        },
        "coefficients": {f: float(final_model.coef_[0][i]) for i, f in enumerate(active_features)},
        "intercept": float(final_model.intercept_[0]),
        "calibration": {
            "method": "platt_grouped_oof",
            "coefficient": float(final_fit["calibration_coefficient"]),
            "intercept": float(final_fit["calibration_intercept"]),
            "group_folds": int(final_fit["calibration_folds"]),
            "oof_rows": int(final_fit["calibration_oof_rows"]),
            "oof_report": final_fit["calibration_oof_report"],
        },
        "risk_thresholds": {
            "medium": float(args.medium_threshold),
            "high": float(args.high_threshold),
            "probability_space": "calibrated",
        },
        "metrics": {
            "holdout": logistic_metrics,
            "benchmark_random_forest": forest_metrics,
        },
        "training": {
            "rows": int(len(y)),
            "students": int(len(np.unique(groups))),
            "positive_rows": int(np.sum(y == 1)),
            "negative_rows": int(np.sum(y == 0)),
            "positive_rate": float(np.mean(y)),
            "train_rows": int(len(train_idx)),
            "test_rows": int(len(test_idx)),
            "test_size": float(args.test_size),
            "seed": int(args.seed),
            "split": "GroupShuffleSplit por student_id",
            "attendance_window_days": int(args.attendance_window),
            "critical_numeric_threshold": float(args.critical_threshold),
            "temporal_policy": "closed_N_to_closed_N_plus_1",
            "probability_calibration": "Platt sobre logits OOF agrupados por student_id",
            "missing_rate_by_feature": full_missing,
            "dropped_all_missing_features": dropped_features,
            "cutoff_source_counts": source_counts,
            "bimester_counts": bimester_counts,
            "bimester_pair_counts": pair_counts,
            "production_model_reason": "Regresion logistica calibrada elegida para inferencia y explicabilidad reproducibles en PHP",
        },
        "warnings": [
            "La probabilidad calibrada es una alerta de apoyo, no una decision automatica.",
            "En inferencia en vivo se usa el ultimo bimestre cerrado para estimar riesgo en el siguiente.",
            "La asistencia faltante se imputa con la mediana y no equivale a 0% ni 100%.",
            "Las metricas y calibracion no deben extrapolarse a otros colegios sin validacion externa.",
        ],
    }
    output.write_text(json.dumps(artifact, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"Modelo exportado: {output}")
    print(f"Esquema: v4 | Filas: {len(y)} | Estudiantes: {len(np.unique(groups))} | Positivos: {np.mean(y):.3f}")
    print(f"Pares temporales: {pair_counts}")
    print(f"Variables usadas: {', '.join(active_features)}")
    if dropped_features:
        print(f"Variables excluidas por estar 100% vacías: {', '.join(dropped_features)}")
    print("Regresión logística CALIBRADA (holdout por estudiante):")
    print(json.dumps(logistic_metrics, ensure_ascii=False, indent=2))
    print("Calibrador final:")
    print(json.dumps(artifact["calibration"], ensure_ascii=False, indent=2))
    print("Random Forest (benchmark, no desplegado):")
    print(json.dumps(forest_metrics, ensure_ascii=False, indent=2))
    print("Datos faltantes por variable:")
    print(json.dumps(full_missing, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
