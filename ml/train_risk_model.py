#!/usr/bin/env python3
"""Entrena y exporta el modelo predictivo de riesgo académico de EduSync.

El split se realiza por student_id. El dataset de entrada debe haber sido
construido únicamente con pares de bimestres cerrados: información al cierre de
N y resultado observado al cierre de N+1. La asistencia faltante se imputa con
la mediana del entrenamiento; nunca equivale a 0% ni 100%.
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
from sklearn.metrics import accuracy_score, average_precision_score, balanced_accuracy_score, confusion_matrix, f1_score, precision_score, recall_score, roc_auc_score
from sklearn.model_selection import GroupShuffleSplit
from sklearn.preprocessing import StandardScaler

FEATURES=["grade_mean_current","grade_mean_previous","grade_trend","critical_records_current","critical_courses_current","attendance_rate_30d","late_30d","absent_30d"]
TARGET="target_next_bimester_risk"


def parse_args():
    p=argparse.ArgumentParser(description="Entrena el modelo predictivo de riesgo de EduSync")
    p.add_argument("--input",required=True)
    p.add_argument("--output",default="edusync/storage/ai_models/risk_model.json")
    p.add_argument("--test-size",type=float,default=0.25)
    p.add_argument("--seed",type=int,default=42)
    p.add_argument("--min-rows",type=int,default=40)
    p.add_argument("--medium-threshold",type=float,default=0.40)
    p.add_argument("--high-threshold",type=float,default=0.70)
    p.add_argument("--attendance-window",type=int,default=30)
    p.add_argument("--critical-threshold",type=float,default=10.5)
    return p.parse_args()


def parse_float(value):
    text="" if value is None else str(value).strip().replace(",", ".")
    return float("nan") if text=="" else float(text)


def load_dataset(path:Path):
    rows=[];raw_rows=[]
    with path.open("r",encoding="utf-8-sig",newline="") as f:
        reader=csv.DictReader(f);fields=set(reader.fieldnames or [])
        missing=[c for c in ["student_id","bimester","target_bimester","cutoff_source",TARGET,*FEATURES] if c not in fields]
        if missing: raise SystemExit("Faltan columnas en el CSV: "+", ".join(missing)+". Reexporta el dataset con la versión temporal actual.")
        for row in reader:
            try:
                x=[parse_float(row.get(feature)) for feature in FEATURES];y=int(float(str(row[TARGET]).strip()));group=str(row["student_id"]).strip()
                base=int(float(str(row["bimester"]).strip()));target=int(float(str(row["target_bimester"]).strip()))
            except (TypeError,ValueError): continue
            if y not in (0,1) or group=="" or target!=base+1: continue
            if not all(math.isfinite(v) for v in x[:5]): continue
            rows.append((x,y,group));raw_rows.append(row)
    if not rows: raise SystemExit("No hay filas válidas para entrenar.")
    X=np.asarray([r[0] for r in rows],dtype=float);y=np.asarray([r[1] for r in rows],dtype=int);groups=np.asarray([r[2] for r in rows])
    return X,y,groups,raw_rows


def metric_bundle(y_true,probabilities,threshold=0.5):
    pred=(probabilities>=threshold).astype(int)
    out={"decision_threshold":float(threshold),"accuracy":float(accuracy_score(y_true,pred)),"balanced_accuracy":float(balanced_accuracy_score(y_true,pred)),"precision":float(precision_score(y_true,pred,zero_division=0)),"recall":float(recall_score(y_true,pred,zero_division=0)),"f1":float(f1_score(y_true,pred,zero_division=0)),"confusion_matrix":confusion_matrix(y_true,pred,labels=[0,1]).tolist()}
    if len(np.unique(y_true))==2:
        out["roc_auc"]=float(roc_auc_score(y_true,probabilities));out["pr_auc"]=float(average_precision_score(y_true,probabilities))
    else: out["roc_auc"]=None;out["pr_auc"]=None
    return out


def missing_rates(X,features): return {feature:float(np.mean(np.isnan(X[:,i]))) for i,feature in enumerate(features)}


def main():
    args=parse_args();src=Path(args.input);X_full,y,groups,raw_rows=load_dataset(src)
    if len(y)<args.min_rows: raise SystemExit(f"Dataset insuficiente: {len(y)} filas. Mínimo configurado: {args.min_rows}.")
    if len(np.unique(y))<2: raise SystemExit("La variable objetivo solo contiene una clase.")
    if len(np.unique(groups))<8: raise SystemExit("Hay muy pocos estudiantes distintos para una evaluación por grupos confiable.")

    active_indexes=[i for i in range(len(FEATURES)) if not np.all(np.isnan(X_full[:,i]))];active_features=[FEATURES[i] for i in active_indexes];dropped_features=[f for i,f in enumerate(FEATURES) if i not in active_indexes];X=X_full[:,active_indexes]
    if len(active_features)<5: raise SystemExit("Demasiadas variables están completamente vacías; revisa el dataset.")

    splitter=GroupShuffleSplit(n_splits=1,test_size=args.test_size,random_state=args.seed);train_idx,test_idx=next(splitter.split(X,y,groups=groups));X_train,X_test=X[train_idx],X[test_idx];y_train,y_test=y[train_idx],y[test_idx]
    if len(np.unique(y_train))<2 or len(np.unique(y_test))<2: raise SystemExit("El split por estudiantes dejó una sola clase en train o test.")

    imputer=SimpleImputer(strategy="median");X_train_imp=imputer.fit_transform(X_train);X_test_imp=imputer.transform(X_test)
    scaler=StandardScaler();X_train_std=scaler.fit_transform(X_train_imp);X_test_std=scaler.transform(X_test_imp)
    logistic=LogisticRegression(class_weight="balanced",max_iter=3000,random_state=args.seed);logistic.fit(X_train_std,y_train);logistic_prob=logistic.predict_proba(X_test_std)[:,1];logistic_metrics=metric_bundle(y_test,logistic_prob)
    forest=RandomForestClassifier(n_estimators=300,min_samples_leaf=2,class_weight="balanced",random_state=args.seed,n_jobs=-1);forest.fit(X_train_imp,y_train);forest_prob=forest.predict_proba(X_test_imp)[:,1];forest_metrics=metric_bundle(y_test,forest_prob)

    final_imputer=SimpleImputer(strategy="median");X_all_imp=final_imputer.fit_transform(X);final_scaler=StandardScaler();X_all_std=final_scaler.fit_transform(X_all_imp);final_model=LogisticRegression(class_weight="balanced",max_iter=3000,random_state=args.seed);final_model.fit(X_all_std,y)

    source_counts={};bimester_counts={};pair_counts={}
    for row in raw_rows:
        source=str(row.get("cutoff_source","") or "sin_fuente");source_counts[source]=source_counts.get(source,0)+1
        base=str(row.get("bimester","") or "?");target=str(row.get("target_bimester","") or "?");bimester_counts[base]=bimester_counts.get(base,0)+1;pair=f"{base}->{target}";pair_counts[pair]=pair_counts.get(pair,0)+1

    full_missing=missing_rates(X_full,FEATURES);output=Path(args.output);output.parent.mkdir(parents=True,exist_ok=True)
    artifact={
        "schema_version":3,
        "model_type":"logistic_regression",
        "created_at":datetime.now(timezone.utc).isoformat(),
        "target":"al_menos_un_curso_critico_en_el_bimestre_siguiente_cerrado",
        "target_definition":{"letter":"C","numeric_rule":f"nota < {args.critical_threshold:g}","numeric_threshold":float(args.critical_threshold),"temporal_rule":"features al cierre del bimestre N; resultado observado al cierre del bimestre N+1"},
        "features":active_features,
        "imputer":{"strategy":"median","fill":{f:float(final_imputer.statistics_[i]) for i,f in enumerate(active_features)}},
        "scaler":{"mean":{f:float(final_scaler.mean_[i]) for i,f in enumerate(active_features)},"scale":{f:float(final_scaler.scale_[i] if abs(final_scaler.scale_[i])>1e-12 else 1.0) for i,f in enumerate(active_features)}},
        "coefficients":{f:float(final_model.coef_[0][i]) for i,f in enumerate(active_features)},"intercept":float(final_model.intercept_[0]),
        "risk_thresholds":{"medium":float(args.medium_threshold),"high":float(args.high_threshold)},
        "metrics":{"holdout":logistic_metrics,"benchmark_random_forest":forest_metrics},
        "training":{"rows":int(len(y)),"students":int(len(np.unique(groups))),"positive_rows":int(np.sum(y==1)),"negative_rows":int(np.sum(y==0)),"positive_rate":float(np.mean(y)),"train_rows":int(len(train_idx)),"test_rows":int(len(test_idx)),"test_size":float(args.test_size),"seed":int(args.seed),"split":"GroupShuffleSplit por student_id","attendance_window_days":int(args.attendance_window),"critical_numeric_threshold":float(args.critical_threshold),"temporal_policy":"closed_N_to_closed_N_plus_1","missing_rate_by_feature":full_missing,"dropped_all_missing_features":dropped_features,"cutoff_source_counts":source_counts,"bimester_counts":bimester_counts,"bimester_pair_counts":pair_counts,"production_model_reason":"Regresion logistica elegida para inferencia y explicabilidad reproducibles en PHP"},
        "warnings":["La probabilidad es una alerta de apoyo, no una decision automatica.","En inferencia en vivo se usa el ultimo bimestre cerrado para estimar riesgo en el bimestre siguiente, aunque este este en proceso.","La asistencia faltante se imputa con la mediana; no se interpreta como 0% ni 100%.","Las metricas no deben extrapolarse a otros colegios sin validacion externa."]
    }
    output.write_text(json.dumps(artifact,ensure_ascii=False,indent=2),encoding="utf-8")

    print(f"Modelo exportado: {output}");print(f"Filas: {len(y)} | Estudiantes: {len(np.unique(groups))} | Positivos: {np.mean(y):.3f}");print(f"Pares temporales: {pair_counts}");print(f"Variables usadas: {', '.join(active_features)}")
    if dropped_features: print(f"Variables excluidas por estar 100% vacías: {', '.join(dropped_features)}")
    print("Regresión logística (holdout por estudiante):");print(json.dumps(logistic_metrics,ensure_ascii=False,indent=2));print("Random Forest (benchmark, no desplegado):");print(json.dumps(forest_metrics,ensure_ascii=False,indent=2));print("Datos faltantes por variable:");print(json.dumps(full_missing,ensure_ascii=False,indent=2))

if __name__=="__main__": main()
