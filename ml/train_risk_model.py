#!/usr/bin/env python3
"""Entrena y exporta el modelo predictivo de riesgo académico de EduSync.

El split de evaluación se realiza por student_id para evitar que el mismo
estudiante aparezca simultáneamente en entrenamiento y prueba.
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
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    accuracy_score,
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


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Entrena el modelo predictivo de riesgo de EduSync")
    p.add_argument("--input", required=True, help="CSV generado por tools/export_risk_dataset.php")
    p.add_argument("--output", default="edusync/storage/ai_models/risk_model.json")
    p.add_argument("--test-size", type=float, default=0.25)
    p.add_argument("--seed", type=int, default=42)
    p.add_argument("--min-rows", type=int, default=40)
    p.add_argument("--medium-threshold", type=float, default=0.40)
    p.add_argument("--high-threshold", type=float, default=0.70)
    p.add_argument("--attendance-window", type=int, default=30)
    return p.parse_args()


def load_dataset(path: Path):
    rows = []
    with path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        missing = [c for c in ["student_id", TARGET, *FEATURES] if c not in (reader.fieldnames or [])]
        if missing:
            raise SystemExit(f"Faltan columnas en el CSV: {', '.join(missing)}")
        for row in reader:
            try:
                x = [float(row[f]) for f in FEATURES]
                y = int(float(row[TARGET]))
                group = str(row["student_id"])
            except (TypeError, ValueError):
                continue
            if y not in (0, 1) or not all(math.isfinite(v) for v in x):
                continue
            rows.append((x, y, group))
    if not rows:
        raise SystemExit("No hay filas válidas para entrenar.")
    X = np.asarray([r[0] for r in rows], dtype=float)
    y = np.asarray([r[1] for r in rows], dtype=int)
    groups = np.asarray([r[2] for r in rows])
    return X, y, groups


def metrics(y_true, probabilities, threshold=0.5):
    pred = (probabilities >= threshold).astype(int)
    out = {
        "accuracy": float(accuracy_score(y_true, pred)),
        "balanced_accuracy": float(balanced_accuracy_score(y_true, pred)),
        "precision": float(precision_score(y_true, pred, zero_division=0)),
        "recall": float(recall_score(y_true, pred, zero_division=0)),
        "f1": float(f1_score(y_true, pred, zero_division=0)),
        "confusion_matrix": confusion_matrix(y_true, pred, labels=[0, 1]).tolist(),
    }
    if len(np.unique(y_true)) == 2:
        out["roc_auc"] = float(roc_auc_score(y_true, probabilities))
    else:
        out["roc_auc"] = None
    return out


def main():
    args = parse_args()
    src = Path(args.input)
    X, y, groups = load_dataset(src)
    if len(y) < args.min_rows:
        raise SystemExit(f"Dataset insuficiente: {len(y)} filas. Mínimo configurado: {args.min_rows}.")
    if len(np.unique(y)) < 2:
        raise SystemExit("La variable objetivo solo contiene una clase; no se puede entrenar un clasificador.")
    if len(np.unique(groups)) < 8:
        raise SystemExit("Hay muy pocos estudiantes distintos para una evaluación por grupos confiable.")

    splitter = GroupShuffleSplit(n_splits=1, test_size=args.test_size, random_state=args.seed)
    train_idx, test_idx = next(splitter.split(X, y, groups=groups))
    X_train, X_test = X[train_idx], X[test_idx]
    y_train, y_test = y[train_idx], y[test_idx]

    if len(np.unique(y_train)) < 2 or len(np.unique(y_test)) < 2:
        raise SystemExit("El split por estudiantes dejó una sola clase en train o test. Reúne más datos o cambia la semilla/test-size.")

    scaler = StandardScaler()
    X_train_std = scaler.fit_transform(X_train)
    X_test_std = scaler.transform(X_test)

    logistic = LogisticRegression(class_weight="balanced", max_iter=3000, random_state=args.seed)
    logistic.fit(X_train_std, y_train)
    logistic_prob = logistic.predict_proba(X_test_std)[:, 1]
    logistic_metrics = metrics(y_test, logistic_prob)

    forest = RandomForestClassifier(
        n_estimators=300,
        min_samples_leaf=2,
        class_weight="balanced",
        random_state=args.seed,
        n_jobs=-1,
    )
    forest.fit(X_train, y_train)
    forest_prob = forest.predict_proba(X_test)[:, 1]
    forest_metrics = metrics(y_test, forest_prob)

    # Modelo de producción: regresión logística por interpretabilidad y porque
    # puede reproducirse exactamente en PHP sin dependencias Python en Hostinger.
    final_scaler = StandardScaler()
    X_all_std = final_scaler.fit_transform(X)
    final_model = LogisticRegression(class_weight="balanced", max_iter=3000, random_state=args.seed)
    final_model.fit(X_all_std, y)

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    artifact = {
        "schema_version": 1,
        "model_type": "logistic_regression",
        "created_at": datetime.now(timezone.utc).isoformat(),
        "target": "al_menos_un_curso_critico_en_el_bimestre_siguiente",
        "features": FEATURES,
        "scaler": {
            "mean": {f: float(final_scaler.mean_[i]) for i, f in enumerate(FEATURES)},
            "scale": {f: float(final_scaler.scale_[i] if abs(final_scaler.scale_[i]) > 1e-12 else 1.0) for i, f in enumerate(FEATURES)},
        },
        "coefficients": {f: float(final_model.coef_[0][i]) for i, f in enumerate(FEATURES)},
        "intercept": float(final_model.intercept_[0]),
        "risk_thresholds": {
            "medium": float(args.medium_threshold),
            "high": float(args.high_threshold),
        },
        "metrics": {
            "holdout": logistic_metrics,
            "benchmark_random_forest": forest_metrics,
        },
        "training": {
            "rows": int(len(y)),
            "students": int(len(np.unique(groups))),
            "positive_rate": float(np.mean(y)),
            "train_rows": int(len(train_idx)),
            "test_rows": int(len(test_idx)),
            "test_size": float(args.test_size),
            "seed": int(args.seed),
            "split": "GroupShuffleSplit por student_id",
            "attendance_window_days": int(args.attendance_window),
            "production_model_reason": "Regresion logistica elegida para inferencia y explicabilidad reproducibles en PHP",
        },
        "warnings": [
            "La probabilidad es una alerta de apoyo, no una decision automatica.",
            "Las metricas deben reportarse con el dataset real y no extrapolarse a otros colegios sin validacion externa.",
        ],
    }
    with output.open("w", encoding="utf-8") as f:
        json.dump(artifact, f, ensure_ascii=False, indent=2)

    print(f"Modelo exportado: {output}")
    print(f"Filas: {len(y)} | Estudiantes: {len(np.unique(groups))} | Positivos: {np.mean(y):.3f}")
    print("Regresión logística (holdout por estudiante):")
    print(json.dumps(logistic_metrics, ensure_ascii=False, indent=2))
    print("Random Forest (benchmark, no desplegado):")
    print(json.dumps(forest_metrics, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
