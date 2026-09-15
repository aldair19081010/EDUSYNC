#!/usr/bin/env python3
"""Compara EduSync v4 con el candidato v5.1 (trayectoria del mismo año)."""
from __future__ import annotations

import argparse
import csv
import json
import math
from datetime import date
from pathlib import Path
from statistics import mean, pstdev

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

V4_FEATURES=[
    "grade_mean_current","grade_trend","previous_bimester_available",
    "critical_records_current","critical_courses_current",
    "attendance_rate_30d","late_30d","absent_30d",
]
V51_FEATURES=[
    "grade_mean_current","grade_trend_same_year","year_periods_available","year_grade_trend",
    "critical_records_current","critical_courses_current","critical_courses_year_mean",
    "attendance_rate_30d","attendance_trend_same_year","late_30d","absent_30d",
]
TARGET="target_next_bimester_risk"
METRIC_NAMES=["recall","precision","f1","balanced_accuracy","roc_auc","pr_auc","brier_score","ece"]


def parse_args():
    p=argparse.ArgumentParser(description="Compara EduSync v4 vs v5.1")
    p.add_argument("--input",required=True)
    p.add_argument("--model",default="edusync/storage/ai_models/risk_model_v51_candidate.json")
    p.add_argument("--output",default="storage/risk_validation_v51_comparison.json")
    p.add_argument("--splits",type=int,default=10)
    p.add_argument("--test-size",type=float,default=0.25)
    p.add_argument("--seed",type=int,default=42)
    p.add_argument("--threshold",type=float,default=0.50)
    p.add_argument("--calibration-folds",type=int,default=5)
    p.add_argument("--max-cases",type=int,default=15)
    p.add_argument("--include-student-id",action="store_true")
    return p.parse_args()


def num(value):
    text="" if value is None else str(value).strip().replace(",",".")
    return float("nan") if text=="" else float(text)


def parse_date(value):
    try:return date.fromisoformat(str(value or "").strip())
    except ValueError:return None


def load(path:Path):
    records=[];required=["student_id","academic_year_id","bimester","target_bimester","cutoff_date","cutoff_source",TARGET,*V4_FEATURES,*V51_FEATURES]
    with path.open("r",encoding="utf-8-sig",newline="") as f:
        reader=csv.DictReader(f);fields=set(reader.fieldnames or []);missing=[c for c in required if c not in fields]
        if missing:raise SystemExit("Faltan columnas para comparar v4/v5.1: "+", ".join(sorted(set(missing))))
        for row in reader:
            try:
                student=str(row["student_id"]).strip();year_id=int(float(row["academic_year_id"]));base=int(float(row["bimester"]));target=int(float(row["target_bimester"]));y=int(float(row[TARGET]));cutoff=parse_date(row["cutoff_date"])
                x4=[num(row.get(f)) for f in V4_FEATURES];x51=[num(row.get(f)) for f in V51_FEATURES]
            except (TypeError,ValueError):continue
            if not student or cutoff is None or y not in (0,1) or target!=base+1:continue
            records.append({"student_id":student,"year_id":year_id,"bimester":base,"target_bimester":target,"cutoff":cutoff,"y":y,"x4":x4,"x51":x51,"row":row})
    if not records:raise SystemExit("No hay filas válidas para comparar.")
    y=np.asarray([r["y"] for r in records],dtype=int);groups=np.asarray([r["student_id"] for r in records]);X4=np.asarray([r["x4"] for r in records],dtype=float);X51=np.asarray([r["x51"] for r in records],dtype=float)
    return records,X4,X51,y,groups


def active_matrix(X,features):
    idx=[i for i in range(len(features)) if not np.all(np.isnan(X[:,i]))]
    return X[:,idx],[features[i] for i in idx]


def sigmoid(values):
    values=np.asarray(values,dtype=float);out=np.empty_like(values,dtype=float);pos=values>=0
    out[pos]=1.0/(1.0+np.exp(-np.minimum(values[pos],700.0)));expv=np.exp(np.maximum(values[~pos],-700.0));out[~pos]=expv/(1.0+expv);return out


def calibration_report(y_true,prob,n_bins=10):
    y_true=np.asarray(y_true,dtype=int);prob=np.clip(np.asarray(prob,dtype=float),0.0,1.0);bins=[];ece=0.0
    for i in range(n_bins):
        lo,hi=i/n_bins,(i+1)/n_bins;mask=(prob>=lo)&(prob<(hi) if i<n_bins-1 else prob<=hi);count=int(np.sum(mask))
        if not count:bins.append({"lower":lo,"upper":hi,"count":0,"mean_predicted":None,"observed_rate":None,"gap":None});continue
        mp=float(np.mean(prob[mask]));obs=float(np.mean(y_true[mask]));gap=abs(mp-obs);ece+=(count/len(y_true))*gap;bins.append({"lower":lo,"upper":hi,"count":count,"mean_predicted":mp,"observed_rate":obs,"gap":gap})
    return {"brier_score":float(brier_score_loss(y_true,prob)),"ece":float(ece),"bins":bins}


def metrics(y_true,prob,threshold):
    pred=(prob>=threshold).astype(int);cm=confusion_matrix(y_true,pred,labels=[0,1])
    out={"accuracy":float(accuracy_score(y_true,pred)),"balanced_accuracy":float(balanced_accuracy_score(y_true,pred)),"precision":float(precision_score(y_true,pred,zero_division=0)),"recall":float(recall_score(y_true,pred,zero_division=0)),"f1":float(f1_score(y_true,pred,zero_division=0)),"confusion_matrix":cm.tolist(),"roc_auc":float(roc_auc_score(y_true,prob)) if len(np.unique(y_true))==2 else None,"pr_auc":float(average_precision_score(y_true,prob)) if len(np.unique(y_true))==2 else None}
    out.update(calibration_report(y_true,prob));return out,pred


def fit_base(X,y,seed):
    imp=SimpleImputer(strategy="median");Xi=imp.fit_transform(X);scaler=StandardScaler();Xs=scaler.fit_transform(Xi);model=LogisticRegression(class_weight="balanced",max_iter=3000,random_state=seed);model.fit(Xs,y);return imp,scaler,model


def decision(X,imp,scaler,model):return model.decision_function(scaler.transform(imp.transform(X)))


def fit_calibrated(X,y,groups,seed,requested_folds):
    unique=np.unique(groups);folds=min(max(2,requested_folds),len(unique));oof=np.full(len(y),np.nan);valid=0
    for fold_no,(tr,va) in enumerate(GroupKFold(n_splits=folds).split(X,y,groups=groups),start=1):
        if len(np.unique(y[tr]))<2 or len(np.unique(y[va]))<2:continue
        imp,scaler,model=fit_base(X[tr],y[tr],seed+fold_no);oof[va]=decision(X[va],imp,scaler,model);valid+=1
    mask=np.isfinite(oof)
    if int(np.sum(mask))<30 or valid<2 or len(np.unique(y[mask]))<2:return None
    cal=LogisticRegression(C=1e6,solver="lbfgs",max_iter=2000);cal.fit(oof[mask].reshape(-1,1),y[mask]);coef=float(cal.coef_[0][0]);intercept=float(cal.intercept_[0])
    if not math.isfinite(coef) or coef<=0 or not math.isfinite(intercept):return None
    imp,scaler,model=fit_base(X,y,seed);return {"imp":imp,"scaler":scaler,"model":model,"coef":coef,"intercept":intercept}


def fit_predict(X,y,groups,tr,te,seed,threshold,cal_folds):
    if len(np.unique(y[tr]))<2 or len(np.unique(y[te]))<2:return None
    fitted=fit_calibrated(X[tr],y[tr],groups[tr],seed,cal_folds)
    if fitted is None:return None
    raw=decision(X[te],fitted["imp"],fitted["scaler"],fitted["model"]);prob=sigmoid(fitted["intercept"]+fitted["coef"]*raw);bundle,pred=metrics(y[te],prob,threshold)
    return {"metrics":bundle,"prob":prob,"pred":pred,"actual":y[te]}


def summarize(values):
    clean=[float(v) for v in values if v is not None and math.isfinite(float(v))]
    return {"mean":mean(clean),"std":pstdev(clean),"min":min(clean),"max":max(clean)} if clean else {"mean":None,"std":None,"min":None,"max":None}


def grouped_compare(X4,X51,y,groups,args):
    splitter=GroupShuffleSplit(n_splits=max(2,args.splits),test_size=args.test_size,random_state=args.seed);rows=[]
    for no,(tr,te) in enumerate(splitter.split(X4,y,groups=groups),start=1):
        r4=fit_predict(X4,y,groups,tr,te,args.seed+no,args.threshold,args.calibration_folds);r51=fit_predict(X51,y,groups,tr,te,args.seed+100+no,args.threshold,args.calibration_folds)
        if r4 is None or r51 is None:continue
        rows.append({"split":no,"v4":r4["metrics"],"v51":r51["metrics"]})
    if not rows:raise SystemExit("No hubo splits agrupados comparables.")
    def summary(version):return {m:summarize([r[version].get(m) for r in rows]) for m in METRIC_NAMES}
    return {"valid_splits":len(rows),"requested_splits":args.splits,"v4":summary("v4"),"v51":summary("v51"),"splits":rows}


def temporal_periods(records):
    periods={}
    for idx,r in enumerate(records):
        key=(r["cutoff"],r["year_id"],r["bimester"],r["target_bimester"]);periods.setdefault(key,[]).append(idx)
    return sorted(periods.items(),key=lambda item:item[0])


def case_counts(actual,pred):
    actual=np.asarray(actual);pred=np.asarray(pred)
    return {"tp":int(np.sum((actual==1)&(pred==1))),"tn":int(np.sum((actual==0)&(pred==0))),"fp":int(np.sum((actual==0)&(pred==1))),"fn":int(np.sum((actual==1)&(pred==0)))}


def selected_fn(records,test_indices,result,max_cases,include_id):
    cases=[]
    for local,global_idx in enumerate(test_indices):
        if int(result["actual"][local])!=1 or int(result["pred"][local])!=0:continue
        rec=records[global_idx];row=rec["row"];item={"academic_year_id":rec["year_id"],"bimester":rec["bimester"],"target_bimester":rec["target_bimester"],"cutoff_date":rec["cutoff"].isoformat(),"nivel":row.get("nivel"),"grado":row.get("grado"),"seccion":row.get("seccion"),"probability":float(result["prob"][local]),"year_periods_available":num(row.get("year_periods_available")),"grade_mean_current":num(row.get("grade_mean_current")),"grade_trend_same_year":num(row.get("grade_trend_same_year")),"year_grade_trend":num(row.get("year_grade_trend")),"critical_courses_current":num(row.get("critical_courses_current"))}
        if include_id:item["student_id"]=rec["student_id"]
        cases.append(item)
    cases.sort(key=lambda x:x["probability"]);return cases[:max_cases]


def temporal_compare(records,X4,X51,y,groups,args):
    evaluations=[];periods=temporal_periods(records);latest_payload=None
    for no,(key,test_list) in enumerate(periods,start=1):
        cutoff,year_id,base,target=key;train=[i for i,r in enumerate(records) if r["cutoff"]<cutoff]
        if not train:continue
        tr=np.asarray(train,dtype=int);te=np.asarray(test_list,dtype=int);r4=fit_predict(X4,y,groups,tr,te,args.seed+1000+no,args.threshold,args.calibration_folds);r51=fit_predict(X51,y,groups,tr,te,args.seed+2000+no,args.threshold,args.calibration_folds)
        if r4 is None or r51 is None:continue
        entry={"period_no":no,"academic_year_id":year_id,"bimester":base,"target_bimester":target,"cutoff_date":cutoff.isoformat(),"train_rows":len(tr),"test_rows":len(te),"v4":r4["metrics"],"v51":r51["metrics"],"v4_cases":case_counts(r4["actual"],r4["pred"]),"v51_cases":case_counts(r51["actual"],r51["pred"])};evaluations.append(entry);latest_payload=(entry,test_list,r4,r51)
    if not evaluations:return {"periods_total":len(periods),"periods_evaluated":0,"periods":[],"latest":None}
    latest,test_list,r4,r51=latest_payload;latest=dict(latest);latest["v51_false_negatives"]=selected_fn(records,test_list,r51,args.max_cases,args.include_student_id)
    return {"periods_total":len(periods),"periods_evaluated":len(evaluations),"periods":evaluations,"latest":latest}


def delta(a,b,name):
    x=a.get(name);y=b.get(name);return None if x is None or y is None else float(x-y)


def main():
    args=parse_args();records,X4raw,X51raw,y,groups=load(Path(args.input));X4,f4=active_matrix(X4raw,V4_FEATURES);X51,f51=active_matrix(X51raw,V51_FEATURES)
    grouped=grouped_compare(X4,X51,y,groups,args);temporal=temporal_compare(records,X4,X51,y,groups,args);blockers=[];warnings=[]
    sources=sorted({str(r["row"].get("cutoff_source") or "") for r in records})
    if any("estimated" in s.lower() for s in sources):blockers.append("Hay cutoffs estimados; no usar para decisión final.")
    model_path=Path(args.model);artifact=None
    if model_path.exists():
        try:artifact=json.loads(model_path.read_text(encoding="utf-8"))
        except Exception:blockers.append("No se pudo leer el artefacto v5.1 candidato.")
    else:blockers.append("No existe el artefacto v5.1 candidato.")
    if isinstance(artifact,dict):
        if int(artifact.get("schema_version",0) or 0)!=51:blockers.append("El artefacto no es schema_version 51.")
        if artifact.get("model_variant")!="same_academic_year_v5_1_candidate":blockers.append("El artefacto no declara la variante v5.1.")
        if artifact.get("deployment_status")!="candidate_not_active":warnings.append("El artefacto no está marcado como candidate_not_active.")

    latest=temporal.get("latest");comparison={}
    if latest:
        comparison={m:delta(latest["v51"],latest["v4"],m) for m in METRIC_NAMES};v4,v51=latest["v4"],latest["v51"]
        if v51.get("ece") is not None and v51["ece"]>0.10:warnings.append("ECE temporal v5.1 > 0.10.")
        if v51.get("brier_score") is not None and v4.get("brier_score") is not None and v51["brier_score"]>v4["brier_score"]+0.02:warnings.append("Brier v5.1 empeora más de 0.02 frente a v4.")
        if v51.get("recall") is not None and v4.get("recall") is not None and v51["recall"]<v4["recall"]-0.03:warnings.append("Recall v5.1 cae más de 0.03 frente a v4.")

    if blockers:status="NO_VALIDO"
    elif not latest:status="REVISAR_SIN_HOLDOUT_TEMPORAL"
    else:
        v4,v51=latest["v4"],latest["v51"]
        maintains=(v51["f1"]>=v4["f1"]-0.01 and v51["roc_auc"]>=v4["roc_auc"]-0.01 and v51["brier_score"]<=v4["brier_score"]+0.01 and v51["ece"]<=0.10)
        improves=(v51["recall"]>v4["recall"]+0.005 or v51["brier_score"]<v4["brier_score"]-0.005 or v51["f1"]>v4["f1"]+0.005)
        status="V51_CANDIDATO_FUERTE" if maintains and improves else "MANTENER_V4_POR_AHORA"

    report={"status":status,"note":"La comparación no promueve automáticamente v5.1 al modelo activo.","dataset":{"rows":len(y),"students":len(np.unique(groups)),"positive_rate":float(np.mean(y)),"v4_features":f4,"v51_features":f51,"cutoff_sources":sources},"grouped_by_student":grouped,"temporal_walk_forward":temporal,"latest_temporal_delta_v51_minus_v4":comparison,"blockers":blockers,"warnings":warnings}
    out=Path(args.output);out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding="utf-8")
    print(f"Reporte comparativo creado: {out}");print(f"Estado: {status}");print(f"Filas: {len(y)} | estudiantes: {len(np.unique(groups))} | riesgo real: {np.mean(y):.1%}");print(f"Splits agrupados comparables: {grouped['valid_splits']}/{args.splits}")
    for version in ["v4","v51"]:
        label="V4" if version=="v4" else "V5.1";print(f"--- {label} agrupado ---")
        for m in METRIC_NAMES:
            s=grouped[version][m]
            if s["mean"] is not None:print(f"{version}_{m}: {s['mean']:.3f} ± {s['std']:.3f}")
    print(f"Periodos temporales comparados: {temporal['periods_evaluated']}/{temporal['periods_total']}")
    if latest:
        print(f"Último holdout: año {latest['academic_year_id']} | {latest['bimester']}->{latest['target_bimester']} | corte {latest['cutoff_date']} | train {latest['train_rows']} | test {latest['test_rows']}")
        for version in ["v4","v51"]:
            label="V4" if version=="v4" else "V5.1";print(f"--- {label} temporal ---")
            for m in METRIC_NAMES:
                value=latest[version].get(m)
                if value is not None:print(f"{version}_{m}: {value:.3f}")
            c=latest[f"{version}_cases"];print(f"{label} TP={c['tp']} | TN={c['tn']} | FP={c['fp']} | FN={c['fn']}")
        print("--- DELTA V5.1 - V4 ---")
        for m,value in comparison.items():
            if value is not None:print(f"delta_{m}: {value:+.3f}")
    if blockers:
        print("BLOQUEADORES:");[print("- "+x) for x in blockers]
    if warnings:
        print("ADVERTENCIAS:");[print("- "+x) for x in warnings]

if __name__=="__main__":main()
