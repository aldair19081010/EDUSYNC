#!/usr/bin/env python3
"""Valida EduSync v7 con separación por alumno y walk-forward temporal."""
from __future__ import annotations
import argparse,json,math
from pathlib import Path
from statistics import mean,pstdev
import numpy as np
from sklearn.model_selection import GroupShuffleSplit
import train_course_risk_model_v7 as core

def args():
 p=argparse.ArgumentParser()
 p.add_argument('--input',required=True)
 p.add_argument('--model',default='edusync/storage/ai_models/course_risk_model.json')
 p.add_argument('--output',default='storage/course_risk_validation_report_v7.json')
 p.add_argument('--splits',type=int,default=10)
 p.add_argument('--test-size',type=float,default=.25)
 p.add_argument('--seed',type=int,default=42)
 p.add_argument('--threshold',type=float,default=.20)
 p.add_argument('--calibration-folds',type=int,default=5)
 p.add_argument('--include-student-key',action='store_true')
 return p.parse_args()

def active(X):
 idx=[i for i in range(len(core.FEATURES)) if not np.all(np.isnan(X[:,i]))]
 return X[:,idx],[core.FEATURES[i] for i in idx]

def predict_split(X,y,g,tr,te,seed,folds,threshold):
 if len(np.unique(y[tr]))<2 or len(np.unique(y[te]))<2:return None
 usable=np.any(np.isfinite(X[tr]),axis=0)
 if not usable.any():return None
 Xtr=X[tr][:,usable];Xte=X[te][:,usable]
 try:imp,sc,m,cc,ci,_,_,_=core.fit_calibrated(Xtr,y[tr],g[tr],seed,folds)
 except SystemExit:return None
 p=core.sigmoid(ci+cc*core.decision(Xte,imp,sc,m))
 met=core.metrics(y[te],p,threshold);pred=(p>=threshold).astype(int)
 return {'metrics':met,'prob':p,'pred':pred,'actual':y[te]}

def summary(vals):
 v=[float(x) for x in vals if x is not None and math.isfinite(float(x))]
 return {'mean':mean(v),'std':pstdev(v),'min':min(v),'max':max(v)} if v else {'mean':None,'std':None,'min':None,'max':None}

def counts(y,p):
 y=np.asarray(y);p=np.asarray(p)
 return {'tp':int(((y==1)&(p==1)).sum()),'tn':int(((y==0)&(p==0)).sum()),'fp':int(((y==0)&(p==1)).sum()),'fn':int(((y==1)&(p==0)).sum())}

def persistent_report(raw,te,actual,prob):
 local=[]
 for j,idx in enumerate(te):
  r=raw[int(idx)]
  try:keep=float(r.get('current_course_critical','0') or 0)>=.5 and float(r.get('previous_course_critical','0') or 0)>=.5
  except ValueError:keep=False
  if keep:local.append(j)
 if not local:return {'rows':0,'observed_critical_rate':None,'mean_predicted_probability':None,'gap':None}
 yy=np.asarray(actual)[local];pp=np.asarray(prob)[local]
 obs=float(yy.mean());mp=float(pp.mean())
 return {'rows':len(local),'observed_critical_rate':obs,'mean_predicted_probability':mp,'gap':mp-obs}

def main():
 a=args();X0,y,g,raw=core.load(a.input);X,features=active(X0);blockers=[];warnings=[]
 artifact=None
 try:artifact=json.loads(Path(a.model).read_text(encoding='utf-8'))
 except Exception:blockers.append('No se pudo leer el modelo v7.')
 if isinstance(artifact,dict):
  if artifact.get('schema_version')!=7 or artifact.get('model_variant')!='student_course_longitudinal_v7':blockers.append('El artefacto no corresponde a v7 longitudinal.')
  expected_mapping={'C':5.0,'B':12.0,'A':15.5,'AD':19.0}
  mapping=artifact.get('grade_mapping') or {}
  if any(k not in mapping or abs(float(mapping[k])-v)>1e-9 for k,v in expected_mapping.items()):blockers.append('El artefacto no usa la escala canónica del Libro de Notas (C=5, B=12, A=15.5, AD=19).')
  meth=artifact.get('methodology',{})
  if meth.get('teacher_id_predictor') is not False:blockers.append('El artefacto no confirma exclusión de teacher_id.')
  if meth.get('future_data_used') is not False:blockers.append('El artefacto no confirma exclusión de información futura.')
  if meth.get('zero_weight_evaluations_excluded') is not True:blockers.append('El artefacto no confirma exclusión de evaluaciones/competencias con peso 0%.')
  if meth.get('requires_official_competency_weight_total_100') is not True:blockers.append('El artefacto no confirma validación de pesos oficiales al 100%.')
 metrics_names=['recall','precision','f1','balanced_accuracy','roc_auc','pr_auc','brier_score','ece']
 splits=[];sp=GroupShuffleSplit(n_splits=max(2,a.splits),test_size=a.test_size,random_state=a.seed)
 for no,(tr,te) in enumerate(sp.split(X,y,groups=g),1):
  r=predict_split(X,y,g,tr,te,a.seed+no,a.calibration_folds,a.threshold)
  if r:splits.append({'split':no,**r['metrics']})
 grouped={m:summary([s.get(m) for s in splits]) for m in metrics_names}
 periods={}
 for i,r in enumerate(raw):
  key=(str(r['cutoff_date']),int(float(r['academic_year_id'])),int(float(r['bimester'])),int(float(r['target_bimester'])))
  periods.setdefault(key,[]).append(i)
 ordered=sorted(periods.items(),key=lambda kv:kv[0]);temporal=[];latest_payload=None
 for no,(key,testlist) in enumerate(ordered,1):
  cutoff,year,base,target=key
  tr=np.asarray([i for i,r in enumerate(raw) if str(r['cutoff_date'])<cutoff],int);te=np.asarray(testlist,int)
  if len(tr)<100:continue
  r=predict_split(X,y,g,tr,te,a.seed+1000+no,a.calibration_folds,a.threshold)
  if not r:continue
  prevalence=float(y[tr].mean());baseline=np.full(len(te),prevalence,dtype=float)
  entry={'academic_year_id':year,'bimester':base,'target_bimester':target,'cutoff_date':cutoff,'train_rows':len(tr),'test_rows':len(te),**r['metrics'],'cases':counts(r['actual'],r['pred']),'brier_baseline_prevalence':float(np.mean((y[te]-baseline)**2)),'persistent_critical_subgroup':persistent_report(raw,te,r['actual'],r['prob'])}
  temporal.append(entry);latest_payload=(entry,te,r)
 latest=None
 if latest_payload:
  latest,te,r=latest_payload;latest=dict(latest);cases=[]
  for local,idx in enumerate(te):
   if int(r['actual'][local])!=1 or int(r['pred'][local])!=0:continue
   row=raw[int(idx)]
   item={'course_name':row.get('course_name'),'probability':float(r['prob'][local]),'academic_year_id':row.get('academic_year_id'),'bimester':row.get('bimester'),'target_bimester':row.get('target_bimester'),'course_mean_current':row.get('course_mean_current'),'previous_course_mean':row.get('previous_course_mean'),'consecutive_critical_periods':row.get('consecutive_critical_periods'),'prior_years_mean':row.get('prior_years_mean'),'attendance_rate_30d':row.get('attendance_rate_30d')}
   if a.include_student_key:item['student_key']=row.get('student_key') or row.get('student_id')
   cases.append(item)
  latest['selected_false_negatives']=sorted(cases,key=lambda x:x['probability'])[:25]
  if latest['ece']>0.10:warnings.append('ECE temporal v7 > 0.10; revisar calibración.')
  if latest['brier_score']>=latest['brier_baseline_prevalence']:blockers.append('v7 no mejora el Brier frente al baseline de prevalencia en el último holdout temporal.')
  if latest['recall']<0.70:warnings.append('Recall temporal v7 < 0.70; se escapan demasiados cursos críticos.')
  if latest['roc_auc'] is not None and latest['roc_auc']<0.70:warnings.append('ROC-AUC temporal v7 < 0.70.')
  sg=latest.get('persistent_critical_subgroup',{})
  if sg.get('rows',0)>=20 and sg.get('gap') is not None and sg['gap']<-.15:warnings.append('v7 subestima en más de 15 puntos el subgrupo con dos periodos críticos consecutivos.')
 persistent_periods=[p['persistent_critical_subgroup'] for p in temporal if (p.get('persistent_critical_subgroup') or {}).get('rows',0)>0]
 persistent_summary={'rows':0,'observed_critical_rate':None,'mean_predicted_probability':None,'gap':None}
 if persistent_periods:
  total=sum(p['rows'] for p in persistent_periods)
  obs=sum(p['rows']*p['observed_critical_rate'] for p in persistent_periods)/total
  pred=sum(p['rows']*p['mean_predicted_probability'] for p in persistent_periods)/total
  persistent_summary={'rows':total,'observed_critical_rate':obs,'mean_predicted_probability':pred,'gap':pred-obs}
  if total>=20 and pred-obs<-.15:warnings.append('v7 subestima en más de 15 puntos el subgrupo persistente agregado en los holdouts temporales disponibles.')
 status='NO_APTO' if blockers else ('REVISAR' if warnings or not latest else 'APTO_PARA_PILOTO')
 report={'status':status,'target':'mismo curso con rendimiento crítico en N+1','rows':len(y),'students':len(np.unique(g)),'positive_rate':float(y.mean()),'features':features,'grouped':{'valid_splits':len(splits),'requested_splits':a.splits,'summary':grouped},'temporal':{'periods_total':len(ordered),'periods_evaluated':len(temporal),'periods':temporal,'latest':latest,'persistent_critical_aggregate':persistent_summary},'blockers':blockers,'warnings':warnings}
 out=Path(a.output);out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(f'Reporte v7 creado: {out}');print(f'Estado: {status}')
 print(f'Filas: {len(y)} | estudiantes estables: {len(np.unique(g))} | crítico real: {y.mean():.1%}')
 if latest:
  print(f'Último holdout: {latest["academic_year_id"]} {latest["bimester"]}->{latest["target_bimester"]} corte {latest["cutoff_date"]}')
  for m in metrics_names:
   v=latest.get(m)
   if v is not None:print(f'temporal_{m}: {v:.3f}')
  print('Subgrupo crítico persistente del último holdout: '+json.dumps(latest.get('persistent_critical_subgroup',{}),ensure_ascii=False))
 print('Subgrupo crítico persistente agregado temporal: '+json.dumps(persistent_summary,ensure_ascii=False))
 if blockers:print('BLOQUEADORES:');[print('- '+x) for x in blockers]
 if warnings:print('ADVERTENCIAS:');[print('- '+x) for x in warnings]

if __name__=='__main__':
 main()
