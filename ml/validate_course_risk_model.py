#!/usr/bin/env python3
"""Valida EduSync v6 por estudiante+curso, con splits por alumno y walk-forward temporal."""
from __future__ import annotations
import argparse,json,math
from pathlib import Path
from statistics import mean,pstdev
import numpy as np
from sklearn.model_selection import GroupShuffleSplit
import train_course_risk_model as core

def args():
 p=argparse.ArgumentParser();p.add_argument('--input',required=True);p.add_argument('--model',default='edusync/storage/ai_models/course_risk_model.json');p.add_argument('--output',default='storage/course_risk_validation_report.json');p.add_argument('--splits',type=int,default=10);p.add_argument('--test-size',type=float,default=.25);p.add_argument('--seed',type=int,default=42);p.add_argument('--threshold',type=float,default=.5);p.add_argument('--calibration-folds',type=int,default=5);p.add_argument('--include-student-id',action='store_true');return p.parse_args()
def active(X):
 idx=[i for i in range(len(core.FEATURES)) if not np.all(np.isnan(X[:,i]))];return X[:,idx],[core.FEATURES[i] for i in idx]
def predict_split(X,y,g,tr,te,seed,folds,threshold):
 if len(np.unique(y[tr]))<2 or len(np.unique(y[te]))<2:return None
 try:imp,sc,m,cc,ci,_,_,_=core.fit_calibrated(X[tr],y[tr],g[tr],seed,folds)
 except SystemExit:return None
 p=core.sigmoid(ci+cc*core.decision(X[te],imp,sc,m));met=core.metrics(y[te],p,threshold);pred=(p>=threshold).astype(int);return {'metrics':met,'prob':p,'pred':pred,'actual':y[te]}
def summary(vals):
 v=[float(x) for x in vals if x is not None and math.isfinite(float(x))];return {'mean':mean(v),'std':pstdev(v),'min':min(v),'max':max(v)} if v else {'mean':None,'std':None,'min':None,'max':None}
def counts(y,p):
 y=np.asarray(y);p=np.asarray(p);return {'tp':int(((y==1)&(p==1)).sum()),'tn':int(((y==0)&(p==0)).sum()),'fp':int(((y==0)&(p==1)).sum()),'fn':int(((y==1)&(p==0)).sum())}
def main():
 a=args();X0,y,g,raw=core.load(a.input);X,features=active(X0);blockers=[];warnings=[]
 artifact=None
 try:artifact=json.loads(Path(a.model).read_text(encoding='utf-8'))
 except Exception:blockers.append('No se pudo leer el modelo v6 por curso.')
 if isinstance(artifact,dict):
  if artifact.get('schema_version')!=6 or artifact.get('model_variant')!='student_course_next_bimester_v6':blockers.append('El artefacto no corresponde al modelo v6 por curso.')
  if artifact.get('methodology',{}).get('teacher_id_predictor') is not False:blockers.append('El artefacto no confirma exclusión de teacher_id como predictor.')
 metrics_names=['recall','precision','f1','balanced_accuracy','roc_auc','pr_auc','brier_score','ece']
 splits=[];sp=GroupShuffleSplit(n_splits=max(2,a.splits),test_size=a.test_size,random_state=a.seed)
 for no,(tr,te) in enumerate(sp.split(X,y,groups=g),1):
  r=predict_split(X,y,g,tr,te,a.seed+no,a.calibration_folds,a.threshold)
  if r:splits.append({'split':no,**r['metrics']})
 grouped={m:summary([s.get(m) for s in splits]) for m in metrics_names}
 # temporal: cada (cutoff,año,bimestre objetivo) es examen; entrenamiento solo con cutoffs anteriores
 periods={}
 for i,r in enumerate(raw):
  key=(str(r['cutoff_date']),int(float(r['academic_year_id'])),int(float(r['bimester'])),int(float(r['target_bimester'])))
  periods.setdefault(key,[]).append(i)
 ordered=sorted(periods.items(),key=lambda kv:kv[0]);temporal=[];latest_payload=None
 for no,(key,testlist) in enumerate(ordered,1):
  cutoff,year,base,target=key;tr=np.asarray([i for i,r in enumerate(raw) if str(r['cutoff_date'])<cutoff],int);te=np.asarray(testlist,int)
  if len(tr)<50:continue
  r=predict_split(X,y,g,tr,te,a.seed+1000+no,a.calibration_folds,a.threshold)
  if not r:continue
  prevalence=float(y[tr].mean());baseline=np.full(len(te),prevalence,dtype=float);entry={'academic_year_id':year,'bimester':base,'target_bimester':target,'cutoff_date':cutoff,'train_rows':len(tr),'test_rows':len(te),**r['metrics'],'cases':counts(r['actual'],r['pred']),'brier_baseline_prevalence':float(np.mean((y[te]-baseline)**2))};temporal.append(entry);latest_payload=(entry,te,r)
 latest=None
 if latest_payload:
  latest,te,r=latest_payload;latest=dict(latest);cases=[]
  for local,idx in enumerate(te):
   if int(r['actual'][local])!=1 or int(r['pred'][local])!=0:continue
   row=raw[idx];item={'course_name':row.get('course_name'),'probability':float(r['prob'][local]),'academic_year_id':row.get('academic_year_id'),'bimester':row.get('bimester'),'target_bimester':row.get('target_bimester'),'course_mean_current':row.get('course_mean_current'),'course_trend':row.get('course_trend'),'attendance_rate_30d':row.get('attendance_rate_30d')}
   if a.include_student_id:item['student_id']=row.get('student_id')
   cases.append(item)
  latest['selected_false_negatives']=sorted(cases,key=lambda x:x['probability'])[:20]
  if latest['ece']>0.10:warnings.append('ECE temporal v6 > 0.10; las probabilidades necesitan revisión.')
  if latest['brier_score']>=latest['brier_baseline_prevalence']:blockers.append('El modelo v6 no mejora el Brier del baseline de prevalencia en el último holdout temporal.')
  if latest['recall']<0.70:warnings.append('Recall temporal v6 < 0.70; se escapan demasiados cursos críticos.')
  if latest['roc_auc'] is not None and latest['roc_auc']<0.70:warnings.append('ROC-AUC temporal v6 < 0.70.')
 status='NO_APTO' if blockers else ('REVISAR' if warnings or not latest else 'APTO_PARA_PILOTO')
 report={'status':status,'target':'mismo curso con rendimiento crítico en N+1','rows':len(y),'students':len(np.unique(g)),'positive_rate':float(y.mean()),'features':features,'grouped':{'valid_splits':len(splits),'requested_splits':a.splits,'summary':grouped},'temporal':{'periods_total':len(ordered),'periods_evaluated':len(temporal),'periods':temporal,'latest':latest},'blockers':blockers,'warnings':warnings}
 out=Path(a.output);out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(f'Reporte v6 creado: {out}');print(f'Estado: {status}');print(f'Filas curso-periodo: {len(y)} | estudiantes: {len(np.unique(g))} | crítico real: {y.mean():.1%}');print(f'Splits agrupados: {len(splits)}/{a.splits}')
 for m in metrics_names:
  s=grouped[m]
  if s['mean'] is not None:print(f'agrupado_{m}: {s["mean"]:.3f} ± {s["std"]:.3f}')
 print(f'Periodos temporales: {len(temporal)}/{len(ordered)}')
 if latest:
  print(f'Último holdout: año {latest["academic_year_id"]} | {latest["bimester"]}->{latest["target_bimester"]} | corte {latest["cutoff_date"]} | train {latest["train_rows"]} | test {latest["test_rows"]}')
  for m in metrics_names:
   v=latest.get(m)
   if v is not None:print(f'temporal_{m}: {v:.3f}')
  c=latest['cases'];print(f'TP={c["tp"]} | TN={c["tn"]} | FP={c["fp"]} | FN={c["fn"]}');print(f'Brier baseline prevalencia: {latest["brier_baseline_prevalence"]:.3f}')
 if blockers:print('BLOQUEADORES:');[print('- '+x) for x in blockers]
 if warnings:print('ADVERTENCIAS:');[print('- '+x) for x in warnings]
if __name__=='__main__':main()
