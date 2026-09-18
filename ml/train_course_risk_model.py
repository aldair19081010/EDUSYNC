#!/usr/bin/env python3
"""Entrena EduSync v6: riesgo del MISMO curso en el bimestre siguiente.

Unidad: estudiante + curso + bimestre base cerrado.
Agrupación de validación/calibración: student_id para evitar que el mismo alumno
aparezca a la vez en aprendizaje y prueba.
"""
from __future__ import annotations
import argparse,csv,json,math
from datetime import datetime,timezone
from pathlib import Path
import numpy as np
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score,balanced_accuracy_score,precision_score,recall_score,f1_score,roc_auc_score,average_precision_score,brier_score_loss,confusion_matrix
from sklearn.model_selection import GroupKFold,GroupShuffleSplit
from sklearn.preprocessing import StandardScaler

FEATURES=[
 'course_mean_current','distance_to_critical','course_trend','previous_course_available',
 'same_year_course_slope','same_year_periods_available','low_grade_rate_current',
 'missing_grade_rate_current','evaluations_count_current','attendance_rate_30d',
 'attendance_trend_same_year','late_30d','absent_30d','class_course_mean_current',
 'class_low_grade_rate_current','class_students_critical_rate','student_vs_class_mean'
]
TARGET='target_course_critical'

def args():
 p=argparse.ArgumentParser();p.add_argument('--input',required=True);p.add_argument('--output',default='edusync/storage/ai_models/course_risk_model.json');p.add_argument('--seed',type=int,default=42);p.add_argument('--test-size',type=float,default=.25);p.add_argument('--min-rows',type=int,default=300);p.add_argument('--calibration-folds',type=int,default=5);p.add_argument('--medium-threshold',type=float,default=.40);p.add_argument('--high-threshold',type=float,default=.70);return p.parse_args()
def f(v):
 s='' if v is None else str(v).strip().replace(',','.');return float('nan') if s=='' else float(s)
def load(path):
 rows=[];raw=[]
 with Path(path).open('r',encoding='utf-8-sig',newline='') as fh:
  rd=csv.DictReader(fh);missing=[x for x in ['student_id','course_id','academic_year_id','bimester','target_bimester','cutoff_date',TARGET,*FEATURES] if x not in (rd.fieldnames or [])]
  if missing:raise SystemExit('Faltan columnas v6: '+', '.join(missing))
  for r in rd:
   try:x=[f(r.get(k)) for k in FEATURES];y=int(float(r[TARGET]));sid=str(r['student_id']).strip();course=str(r['course_id']).strip()
   except (ValueError,TypeError):continue
   if y not in(0,1) or not sid or not course:continue
   rows.append((x,y,sid));raw.append(r)
 if not rows:raise SystemExit('No hay filas válidas.')
 return np.asarray([r[0] for r in rows],float),np.asarray([r[1] for r in rows],int),np.asarray([r[2] for r in rows]),raw
def sigmoid(z):
 z=np.asarray(z,float);return 1/(1+np.exp(-np.clip(z,-700,700)))
def cal_report(y,p,bins=10):
 y=np.asarray(y);p=np.clip(np.asarray(p),0,1);out=[];ece=0
 for i in range(bins):
  lo,hi=i/bins,(i+1)/bins;m=(p>=lo)&(p<(hi) if i<bins-1 else p<=hi);n=int(m.sum())
  if not n:out.append({'lower':lo,'upper':hi,'count':0,'mean_predicted':None,'observed_rate':None,'gap':None});continue
  mp=float(p[m].mean());obs=float(y[m].mean());gap=abs(mp-obs);ece+=n/len(y)*gap;out.append({'lower':lo,'upper':hi,'count':n,'mean_predicted':mp,'observed_rate':obs,'gap':gap})
 return {'brier_score':float(brier_score_loss(y,p)),'ece':float(ece),'bins':out}
def metrics(y,p,t=.5):
 pred=(p>=t).astype(int);cm=confusion_matrix(y,pred,labels=[0,1]);d={'accuracy':float(accuracy_score(y,pred)),'balanced_accuracy':float(balanced_accuracy_score(y,pred)),'precision':float(precision_score(y,pred,zero_division=0)),'recall':float(recall_score(y,pred,zero_division=0)),'f1':float(f1_score(y,pred,zero_division=0)),'roc_auc':float(roc_auc_score(y,p)) if len(np.unique(y))==2 else None,'pr_auc':float(average_precision_score(y,p)) if len(np.unique(y))==2 else None,'confusion_matrix':cm.tolist()};d.update(cal_report(y,p));return d
def fit_base(X,y,seed):
 imp=SimpleImputer(strategy='median');Xi=imp.fit_transform(X);sc=StandardScaler();Xs=sc.fit_transform(Xi);m=LogisticRegression(class_weight='balanced',max_iter=4000,random_state=seed);m.fit(Xs,y);return imp,sc,m
def decision(X,imp,sc,m):return m.decision_function(sc.transform(imp.transform(X)))
def fit_calibrated(X,y,g,seed,folds):
 k=min(max(2,folds),len(np.unique(g)));oof=np.full(len(y),np.nan);valid=0
 for no,(tr,va) in enumerate(GroupKFold(n_splits=k).split(X,y,groups=g),1):
  if len(np.unique(y[tr]))<2 or len(np.unique(y[va]))<2:continue
  imp,sc,m=fit_base(X[tr],y[tr],seed+no);oof[va]=decision(X[va],imp,sc,m);valid+=1
 mask=np.isfinite(oof)
 if mask.sum()<50 or valid<2 or len(np.unique(y[mask]))<2:raise SystemExit('No hay suficientes folds para calibrar.')
 cal=LogisticRegression(C=1e6,solver='lbfgs',max_iter=2000);cal.fit(oof[mask].reshape(-1,1),y[mask]);coef=float(cal.coef_[0,0]);inter=float(cal.intercept_[0])
 if not math.isfinite(coef) or coef<=0:raise SystemExit('Calibración inválida.')
 imp,sc,m=fit_base(X,y,seed+1000);return imp,sc,m,coef,inter,valid,int(mask.sum()),cal_report(y[mask],sigmoid(inter+coef*oof[mask]))
def main():
 a=args();X0,y,g,raw=load(a.input)
 if len(y)<a.min_rows:raise SystemExit(f'Dataset pequeño: {len(y)} filas; mínimo {a.min_rows}.')
 if len(np.unique(y))<2:raise SystemExit('El objetivo solo contiene una clase.')
 active=[i for i in range(len(FEATURES)) if not np.all(np.isnan(X0[:,i]))];X=X0[:,active];features=[FEATURES[i] for i in active];dropped=[FEATURES[i] for i in range(len(FEATURES)) if i not in active]
 split=GroupShuffleSplit(n_splits=1,test_size=a.test_size,random_state=a.seed);tr,te=next(split.split(X,y,groups=g));imp,sc,m,cc,ci,_,_,_=fit_calibrated(X[tr],y[tr],g[tr],a.seed,a.calibration_folds);ptest=sigmoid(ci+cc*decision(X[te],imp,sc,m));hold=metrics(y[te],ptest)
 imp,sc,m,cc,ci,valid,oofn,oofrep=fit_calibrated(X,y,g,a.seed+5000,a.calibration_folds)
 pairs={};courses={};years={}
 for r in raw:
  pair=f"{r['bimester']}->{r['target_bimester']}";pairs[pair]=pairs.get(pair,0)+1;courses[r.get('course_name','Curso')]=courses.get(r.get('course_name','Curso'),0)+1;years[str(r['academic_year_id'])]=years.get(str(r['academic_year_id']),0)+1
 artifact={'schema_version':6,'model_variant':'student_course_next_bimester_v6','model_type':'logistic_regression','created_at':datetime.now(timezone.utc).isoformat(),'target':'mismo_curso_con_rendimiento_critico_en_bimestre_siguiente','target_definition':{'numeric_rule':'promedio normalizado del curso < 10.5','letter_rule':'C se normaliza como 10 para tendencia y se considera calificación baja','missing_grade':'dato faltante; nunca se convierte en cero','warning':'Rendimiento crítico operativo, no sentencia de desaprobación anual.'},'features':features,'dropped_all_missing_features':dropped,'imputer':{'strategy':'median','fill':{f:float(imp.statistics_[i]) for i,f in enumerate(features)}},'scaler':{'mean':{f:float(sc.mean_[i]) for i,f in enumerate(features)},'scale':{f:float(sc.scale_[i] if abs(sc.scale_[i])>1e-12 else 1) for i,f in enumerate(features)}},'coefficients':{f:float(m.coef_[0,i]) for i,f in enumerate(features)},'intercept':float(m.intercept_[0]),'calibration':{'method':'platt_grouped_oof','coefficient':cc,'intercept':ci,'group_folds':valid,'oof_rows':oofn,'oof_report':oofrep},'risk_thresholds':{'medium':a.medium_threshold,'high':a.high_threshold,'probability_space':'calibrated'},'metrics':{'holdout_grouped_student':hold},'training':{'rows':len(y),'students':len(np.unique(g)),'positive_rate':float(y.mean()),'bimester_pair_counts':pairs,'academic_year_counts':years,'course_counts':courses},'methodology':{'unit':'student_course_bimester','teacher_id_predictor':False,'attendance_used':True,'class_context_used':True,'future_data_used':False}}
 out=Path(a.output);out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(artifact,ensure_ascii=False,indent=2),encoding='utf-8')
 print(f'Modelo v6 por curso creado: {out}');print(f'Filas: {len(y)} | estudiantes: {len(np.unique(g))} | rendimiento crítico real: {y.mean():.1%}');print('Variables: '+', '.join(features));
 if dropped:print('Variables vacías excluidas: '+', '.join(dropped))
 for k in ['recall','precision','f1','balanced_accuracy','roc_auc','pr_auc','brier_score','ece']:
  v=hold.get(k);print(f'v6_{k}: {v:.3f}' if v is not None else f'v6_{k}: N/D')
 cm=hold['confusion_matrix'];print(f'Holdout TN={cm[0][0]} | FP={cm[0][1]} | FN={cm[1][0]} | TP={cm[1][1]}')
if __name__=='__main__':main()
