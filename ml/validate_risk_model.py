#!/usr/bin/env python3
"""Auditoría metodológica del dataset/modelo de alerta temprana EduSync.

La validación exige el esquema temporal v3: bimestre N cerrado -> bimestre N+1
cerrado. Las filas del bimestre actualmente en proceso no pueden actuar como
resultado histórico final.
"""
from __future__ import annotations
import argparse,csv,json,math
from pathlib import Path
from statistics import mean,pstdev
import numpy as np
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score,average_precision_score,balanced_accuracy_score,confusion_matrix,f1_score,precision_score,recall_score,roc_auc_score
from sklearn.model_selection import GroupShuffleSplit
from sklearn.preprocessing import StandardScaler

FEATURES=["grade_mean_current","grade_mean_previous","grade_trend","critical_records_current","critical_courses_current","attendance_rate_30d","late_30d","absent_30d"]
TARGET="target_next_bimester_risk"

def args_parser():
    p=argparse.ArgumentParser(description="Valida el dataset/modelo de riesgo académico EduSync");p.add_argument("--input",required=True);p.add_argument("--model",default="edusync/storage/ai_models/risk_model.json");p.add_argument("--output",default="storage/risk_validation_report.json");p.add_argument("--splits",type=int,default=10);p.add_argument("--test-size",type=float,default=0.25);p.add_argument("--seed",type=int,default=42);p.add_argument("--threshold",type=float,default=0.50);return p.parse_args()

def num(value):
    text="" if value is None else str(value).strip().replace(",", ".");return float("nan") if text=="" else float(text)

def load(path:Path):
    records=[]
    with path.open("r",encoding="utf-8-sig",newline="") as f:
        reader=csv.DictReader(f);fields=set(reader.fieldnames or []);required=["student_id","bimester","target_bimester","cutoff_source",TARGET,*FEATURES];missing=[x for x in required if x not in fields]
        if missing: raise SystemExit("Faltan columnas del esquema temporal v3: "+", ".join(missing)+". Reexporta el dataset.")
        for row in reader:
            try:
                x=[num(row.get(feature)) for feature in FEATURES];y=int(float(row[TARGET]));student=str(row["student_id"]).strip();base=int(float(row["bimester"]));target=int(float(row["target_bimester"]))
            except (ValueError,TypeError): continue
            if y not in (0,1) or not student or target!=base+1: continue
            if not all(math.isfinite(v) for v in x[:5]): continue
            records.append({"x":x,"y":y,"student_id":student,"row":row})
    if not records: raise SystemExit("No hay filas válidas.")
    X=np.asarray([r["x"] for r in records],dtype=float);y=np.asarray([r["y"] for r in records],dtype=int);groups=np.asarray([r["student_id"] for r in records]);return records,X,y,groups

def metrics(y_true,prob,threshold):
    pred=(prob>=threshold).astype(int);result={"accuracy":float(accuracy_score(y_true,pred)),"balanced_accuracy":float(balanced_accuracy_score(y_true,pred)),"precision":float(precision_score(y_true,pred,zero_division=0)),"recall":float(recall_score(y_true,pred,zero_division=0)),"f1":float(f1_score(y_true,pred,zero_division=0)),"confusion_matrix":confusion_matrix(y_true,pred,labels=[0,1]).tolist()}
    if len(np.unique(y_true))==2: result["roc_auc"]=float(roc_auc_score(y_true,prob));result["pr_auc"]=float(average_precision_score(y_true,prob))
    else: result["roc_auc"]=None;result["pr_auc"]=None
    return result,pred

def summarize(values):
    clean=[float(x) for x in values if x is not None and math.isfinite(float(x))];return {"mean":mean(clean),"std":pstdev(clean),"min":min(clean),"max":max(clean)} if clean else {"mean":None,"std":None,"min":None,"max":None}

def count_by(records,field):
    out={}
    for rec in records:
        key=str(rec["row"].get(field,"") or "sin_dato");out[key]=out.get(key,0)+1
    return dict(sorted(out.items(),key=lambda kv:kv[0]))

def pair_counts(records):
    out={}
    for rec in records:
        row=rec["row"];key=f"{row.get('bimester','?')}->{row.get('target_bimester','?')}";out[key]=out.get(key,0)+1
    return dict(sorted(out.items()))

def main():
    args=args_parser();records,X_full,y,groups=load(Path(args.input));students=len(np.unique(groups));positive_rate=float(np.mean(y));missing_rates={f:float(np.mean(np.isnan(X_full[:,i]))) for i,f in enumerate(FEATURES)}
    active_idx=[i for i in range(len(FEATURES)) if not np.all(np.isnan(X_full[:,i]))];active_features=[FEATURES[i] for i in active_idx];X=X_full[:,active_idx]
    splitter=GroupShuffleSplit(n_splits=max(2,args.splits),test_size=args.test_size,random_state=args.seed);split_metrics=[];reference=None;valid_splits=0
    for split_no,(train_idx,test_idx) in enumerate(splitter.split(X,y,groups=groups),start=1):
        y_train,y_test=y[train_idx],y[test_idx]
        if len(np.unique(y_train))<2 or len(np.unique(y_test))<2: continue
        imputer=SimpleImputer(strategy="median");X_train=imputer.fit_transform(X[train_idx]);X_test=imputer.transform(X[test_idx]);scaler=StandardScaler();X_train=scaler.fit_transform(X_train);X_test=scaler.transform(X_test);model=LogisticRegression(class_weight="balanced",max_iter=3000,random_state=args.seed+split_no);model.fit(X_train,y_train);prob=model.predict_proba(X_test)[:,1];bundle,pred=metrics(y_test,prob,args.threshold);bundle.update({"split":split_no,"train_rows":int(len(train_idx)),"test_rows":int(len(test_idx)),"test_students":int(len(np.unique(groups[test_idx])))});split_metrics.append(bundle);valid_splits+=1
        if reference is None:
            cases=[]
            for local_i,global_i in enumerate(test_idx):
                row=records[global_i]["row"];actual=int(y_test[local_i]);predicted=int(pred[local_i]);kind="verdadero_positivo" if predicted==1 and actual==1 else "verdadero_negativo" if predicted==0 and actual==0 else "falso_positivo" if predicted==1 else "falso_negativo"
                cases.append({"student_id":records[global_i]["student_id"],"academic_year_id":row.get("academic_year_id"),"bimester":row.get("bimester"),"target_bimester":row.get("target_bimester"),"cutoff_source":row.get("cutoff_source"),"actual":actual,"predicted":predicted,"probability":float(prob[local_i]),"case_type":kind})
            reference={"metrics":bundle,"true_positives":[x for x in cases if x["case_type"]=="verdadero_positivo"][:10],"true_negatives":[x for x in cases if x["case_type"]=="verdadero_negativo"][:10],"false_positives":sorted([x for x in cases if x["case_type"]=="falso_positivo"],key=lambda e:-e["probability"])[:10],"false_negatives":sorted([x for x in cases if x["case_type"]=="falso_negativo"],key=lambda e:e["probability"])[:10]}
    if valid_splits==0: raise SystemExit("Ningún split produjo ambas clases en train y test.")

    names=["accuracy","balanced_accuracy","precision","recall","f1","roc_auc","pr_auc"];repeated={name:summarize([m.get(name) for m in split_metrics]) for name in names};model_path=Path(args.model);model_info=None
    if model_path.exists():
        try:model_info=json.loads(model_path.read_text(encoding="utf-8"))
        except Exception:model_info={"error":"No se pudo leer el JSON del modelo."}

    warnings=[];blockers=[];cutoff_sources=count_by(records,"cutoff_source")
    if len(y)<100:warnings.append("Menos de 100 observaciones históricas: reportar métricas con cautela.")
    if students<30:warnings.append("Menos de 30 estudiantes distintos: la estimación de generalización es inestable.")
    if positive_rate<0.10 or positive_rate>0.90:warnings.append("La variable objetivo está fuertemente desbalanceada.")
    if missing_rates["attendance_rate_30d"]>0.50:warnings.append("Más del 50% de las observaciones no tiene asistencia suficiente.")
    if any("estimated" in source.lower() for source in cutoff_sources):blockers.append("Hay fechas de corte estimadas. Para la tesis final reexporta con cierres/fechas reales.")
    invalid_sources=[source for source in cutoff_sources if not source.startswith("closure:") and "estimated" not in source.lower()]
    if invalid_sources:blockers.append("El dataset contiene fuentes temporales anteriores a la política de cierres: "+", ".join(invalid_sources))
    if repeated["recall"]["mean"] is not None and repeated["recall"]["mean"]<0.60:warnings.append("Recall medio menor a 0.60: se pueden perder demasiados casos reales de riesgo.")
    if repeated["f1"]["mean"] is not None and repeated["f1"]["mean"]<0.55:warnings.append("F1 medio menor a 0.55: revisar modelo antes del piloto.")
    if isinstance(model_info,dict):
        if int(model_info.get("schema_version",0) or 0)<3:blockers.append("risk_model.json es anterior al esquema temporal v3 y debe reentrenarse.")
        if (model_info.get("training") or {}).get("temporal_policy")!="closed_N_to_closed_N_plus_1":blockers.append("El modelo no declara la política temporal de bimestres cerrados.")
        threshold=((model_info.get("target_definition") or {}).get("numeric_threshold"))
        if threshold is not None and abs(float(threshold)-10.5)>1e-9:blockers.append("El modelo no usa el criterio crítico <10.5 esperado.")

    report={"status":"NO_APTO_PARA_MAIN" if blockers else ("REVISAR" if warnings else "APTO_PARA_PILOTO"),"dataset":{"rows":int(len(y)),"students":int(students),"positive_rows":int(np.sum(y==1)),"negative_rows":int(np.sum(y==0)),"positive_rate":positive_rate,"features_used_for_validation":active_features,"missing_rate_by_feature":missing_rates,"cutoff_sources":cutoff_sources,"academic_years":count_by(records,"academic_year_id"),"bimester_pairs":pair_counts(records)},"validation":{"method":"Repeated GroupShuffleSplit por student_id sobre pares cerrados N->N+1","requested_splits":int(args.splits),"valid_splits":int(valid_splits),"test_size":float(args.test_size),"decision_threshold":float(args.threshold),"metrics_summary":repeated,"splits":split_metrics,"reference_split":reference},"model_artifact":{"path":str(model_path),"available":model_path.exists(),"schema_version":model_info.get("schema_version") if isinstance(model_info,dict) else None,"created_at":model_info.get("created_at") if isinstance(model_info,dict) else None,"temporal_policy":((model_info.get("training") or {}).get("temporal_policy")) if isinstance(model_info,dict) else None},"blockers":blockers,"warnings":warnings,"interpretation":["La predicción en vivo parte del último bimestre cerrado y apunta al siguiente bimestre, aunque esté en proceso.","La validación histórica solo usa resultados de bimestres siguientes ya cerrados.","Recall indica cuántos casos reales de riesgo fueron detectados.","Precision indica cuántas alertas emitidas correspondieron a riesgo real.","Los falsos negativos deben revisarse antes del despliegue."]}
    output=Path(args.output);output.parent.mkdir(parents=True,exist_ok=True);output.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding="utf-8")
    print(f"Reporte creado: {output}");print(f"Estado: {report['status']}");print(f"Filas: {len(y)} | estudiantes: {students} | riesgo real: {positive_rate:.1%}");print("Pares cerrados: "+json.dumps(report["dataset"]["bimester_pairs"],ensure_ascii=False));print(f"Splits válidos: {valid_splits}/{args.splits}")
    for name in ["recall","precision","f1","balanced_accuracy","roc_auc","pr_auc"]:
        stats=repeated[name]
        if stats["mean"] is not None:print(f"{name}: {stats['mean']:.3f} ± {stats['std']:.3f}")
    if blockers:
        print("BLOQUEADORES:");[print(f"- {item}") for item in blockers]
    if warnings:
        print("ADVERTENCIAS:");[print(f"- {item}") for item in warnings]

if __name__=="__main__":main()
