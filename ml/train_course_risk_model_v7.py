#!/usr/bin/env python3
"""EduSync v7: modelo longitudinal estudiante + curso + bimestre.

Usa únicamente información disponible hasta el cierre del bimestre fuente:
trayectoria del mismo año, historial de años anteriores, dinámica de evaluaciones,
competencias, asistencia y contexto del aula/curso. Nunca usa datos del bimestre
objetivo como predictor.
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
 'previous_course_mean','previous_course_critical','current_course_critical',
 'consecutive_critical_periods','critical_period_rate_same_year','same_year_mean',
 'same_year_min_mean','same_year_course_slope','same_year_periods_available',
 'low_grade_rate_current','missing_grade_rate_current','evaluations_count_current',
 'evaluation_mean_last','evaluation_mean_recent3','evaluation_trend_current',
 'evaluation_std_current','low_evaluation_rate_current','consecutive_low_evaluations_current',
 'competencies_graded_current','critical_competency_count_current',
 'critical_competency_rate_current','critical_competency_weight_rate_current',
 'min_competency_mean_current','competency_std_current','attendance_rate_30d',
 'attendance_trend_same_year','late_30d','absent_30d','class_course_mean_current',
 'class_low_grade_rate_current','class_students_critical_rate','student_vs_class_mean',
 'prior_years_periods_available','prior_years_mean','prior_years_last_mean',
 'prior_years_slope','prior_years_critical_rate','prior_years_last_critical',
 'prior_years_same_bimester_mean','prior_years_persistence_after_critical_rate'
]
TARGET='target_course_critical'

def args():
 p=argparse.ArgumentParser()
 p.add_argument('--input',required=True)
 p.add_argument('--output',default='edusync/storage/ai_models/course_risk_model.json')
 p.add_argument('--seed',type=int,default=42)
 p.add_argument('--test-size',type=float,default=.25)
 p.add_argument('--min-rows',type=int,default=300)
 p.add_argument('--calibration-folds',type=int,default=5)
 p.add_argument('--medium-threshold',type=float,default=.20)
 p.add_argument('--high-threshold',type=float,default=.40)
 return p.parse_args()

def f(v):
 s='' if v is None else str(v).strip().replace(',','.')
 return float('nan') if s=='' else float(s)

def load(path):
 rows=[];raw=[]
 with Path(path).open('r',encoding='utf-8-sig',newline='') as fh:
  rd=csv.DictReader(fh)
  required=['student_id','course_id','academic_year_id','bimester','target_bimester','cutoff_date',TARGET,*FEATURES]
  missing=[x for x in required if x not in (rd.fieldnames or [])]
  if missing: raise SystemExit('Faltan columnas v7: '+', '.join(missing))
  for r in rd:
   try:
    x=[f(r.get(k)) for k in FEATURES]
    y=int(float(r[TARGET]))
    sid=(r.get('student_key') or r.get('student_id') or '').strip()
    course=str(r.get('course_id','')).strip()
   except (ValueError,TypeError):
    continue
   if y not in (0,1) or not sid or not course: continue
   rows.append((x,y,sid));raw.append(r)
 if not rows: raise SystemExit('No hay filas válidas.')
 return np.asarray([r[0] for r in rows],float),np.asarray([r[1] for r in rows],int),np.asarray([r[2] for r in rows]),raw

def sigmoid(z):
 z=np.asarray(z,float)
 return 1/(1+np.exp(-np.clip(z,-700,700)))

def cal_report(y,p,bins=10):
 y=np.asarray(y);p=np.clip(np.asarray(p),0,1);out=[];ece=0.0
 for i in range(bins):
  lo,hi=i/bins,(i+1)/bins
  m=(p>=lo)&(p<(hi) if i<bins-1 else p<=hi);n=int(m.sum())
  if not n:
   out.append({'lower':lo,'upper':hi,'count':0,'mean_predicted':None,'observed_rate':None,'gap':None});continue
  mp=float(p[m].mean());obs=float(y[m].mean());gap=abs(mp-obs);ece+=n/len(y)*gap
  out.append({'lower':lo,'upper':hi,'count':n,'mean_predicted':mp,'observed_rate':obs,'gap':gap})
 return {'brier_score':float(brier_score_loss(y,p)),'ece':float(ece),'bins':out}

def metrics(y,p,t=.5):
 pred=(p>=t).astype(int);cm=confusion_matrix(y,pred,labels=[0,1])
 d={'accuracy':float(accuracy_score(y,pred)),
    'balanced_accuracy':float(balanced_accuracy_score(y,pred)),
    'precision':float(precision_score(y,pred,zero_division=0)),
    'recall':float(recall_score(y,pred,zero_division=0)),
    'f1':float(f1_score(y,pred,zero_division=0)),
    'roc_auc':float(roc_auc_score(y,p)) if len(np.unique(y))==2 else None,
    'pr_auc':float(average_precision_score(y,p)) if len(np.unique(y))==2 else None,
    'confusion_matrix':cm.tolist()}
 d.update(cal_report(y,p));return d

def fit_base(X,y,seed):
 imp=SimpleImputer(strategy='median');Xi=imp.fit_transform(X)
 sc=StandardScaler();Xs=sc.fit_transform(Xi)
 m=LogisticRegression(class_weight='balanced',max_iter=5000,random_state=seed)
 m.fit(Xs,y);return imp,sc,m

def decision(X,imp,sc,m):
 return m.decision_function(sc.transform(imp.transform(X)))

def fit_calibrated(X,y,g,seed,folds):
 k=min(max(2,folds),len(np.unique(g)));oof=np.full(len(y),np.nan);valid=0
 for no,(tr,va) in enumerate(GroupKFold(n_splits=k).split(X,y,groups=g),1):
  if len(np.unique(y[tr]))<2 or len(np.unique(y[va]))<2: continue
  imp,sc,m=fit_base(X[tr],y[tr],seed+no)
  oof[va]=decision(X[va],imp,sc,m);valid+=1
 mask=np.isfinite(oof)
 if mask.sum()<50 or valid<2 or len(np.unique(y[mask]))<2:
  raise SystemExit('No hay suficientes folds para calibrar v7.')
 cal=LogisticRegression(C=1e6,solver='lbfgs',max_iter=2000)
 cal.fit(oof[mask].reshape(-1,1),y[mask])
 coef=float(cal.coef_[0,0]);inter=float(cal.intercept_[0])
 if not math.isfinite(coef) or coef<=0: raise SystemExit('Calibración inválida.')
 imp,sc,m=fit_base(X,y,seed+1000)
 return imp,sc,m,coef,inter,valid,int(mask.sum()),cal_report(y[mask],sigmoid(inter+coef*oof[mask]))

def subgroup_report(raw,indices,y,p):
 mask=[]
 for local,idx in enumerate(indices):
  r=raw[int(idx)]
  try: persistent=float(r.get('current_course_critical','0') or 0)>=.5 and float(r.get('previous_course_critical','0') or 0)>=.5
  except ValueError: persistent=False
  if persistent: mask.append(local)
 if not mask:return {'rows':0,'observed_critical_rate':None,'mean_predicted_probability':None,'brier_score':None}
 yy=np.asarray(y)[mask];pp=np.asarray(p)[mask]
 return {'rows':len(mask),'observed_critical_rate':float(yy.mean()),'mean_predicted_probability':float(pp.mean()),'brier_score':float(brier_score_loss(yy,pp))}

def main():
 a=args();X0,y,g,raw=load(a.input)
 if len(y)<a.min_rows:raise SystemExit(f'Dataset pequeño: {len(y)} filas; mínimo {a.min_rows}.')
 if len(np.unique(y))<2:raise SystemExit('El objetivo solo contiene una clase.')
 active=[i for i in range(len(FEATURES)) if not np.all(np.isnan(X0[:,i]))]
 X=X0[:,active];features=[FEATURES[i] for i in active];dropped=[FEATURES[i] for i in range(len(FEATURES)) if i not in active]
 split=GroupShuffleSplit(n_splits=1,test_size=a.test_size,random_state=a.seed)
 tr,te=next(split.split(X,y,groups=g))
 imp,sc,m,cc,ci,_,_,_=fit_calibrated(X[tr],y[tr],g[tr],a.seed,a.calibration_folds)
 ptest=sigmoid(ci+cc*decision(X[te],imp,sc,m));hold=metrics(y[te],ptest,a.medium_threshold)
 persistent=subgroup_report(raw,te,y[te],ptest)
 imp,sc,m,cc,ci,valid,oofn,oofrep=fit_calibrated(X,y,g,a.seed+5000,a.calibration_folds)
 pairs={};courses={};years={}
 for r in raw:
  pair=f"{r['bimester']}->{r['target_bimester']}";pairs[pair]=pairs.get(pair,0)+1
  name=r.get('course_name','Curso');courses[name]=courses.get(name,0)+1
  year=str(r['academic_year_id']);years[year]=years.get(year,0)+1
 artifact={
  'schema_version':7,
  'model_variant':'student_course_longitudinal_v7',
  'model_type':'logistic_regression',
  'created_at':datetime.now(timezone.utc).isoformat(),
  'target':'mismo_curso_con_rendimiento_critico_en_bimestre_siguiente',
  'target_definition':{
   'numeric_rule':'promedio ponderado del curso < 10.5',
   'letter_mapping':'C=5, B=12, A=15.5, AD=19; misma escala canónica del Libro de Notas',
   'missing_grade':'dato faltante; nunca se convierte en cero',
   'warning':'Rendimiento crítico operativo, no sentencia de desaprobación anual.'
  },
  'grade_mapping':{'C':5.0,'B':12.0,'A':15.5,'AD':19.0},
  'features':features,
  'dropped_all_missing_features':dropped,
  'imputer':{'strategy':'median','fill':{f:float(imp.statistics_[i]) for i,f in enumerate(features)}},
  'scaler':{'mean':{f:float(sc.mean_[i]) for i,f in enumerate(features)},'scale':{f:float(sc.scale_[i] if abs(sc.scale_[i])>1e-12 else 1) for i,f in enumerate(features)}},
  'coefficients':{f:float(m.coef_[0,i]) for i,f in enumerate(features)},
  'intercept':float(m.intercept_[0]),
  'calibration':{'method':'platt_grouped_oof','coefficient':cc,'intercept':ci,'group_folds':valid,'oof_rows':oofn,'oof_report':oofrep},
  'risk_thresholds':{'medium':a.medium_threshold,'high':a.high_threshold,'probability_space':'calibrated'},
  'metrics':{'holdout_grouped_student':hold,'persistent_critical_subgroup':persistent},
  'training':{'rows':len(y),'students':len(np.unique(g)),'positive_rate':float(y.mean()),'bimester_pair_counts':pairs,'academic_year_counts':years,'course_counts':courses},
  'methodology':{
   'unit':'student_course_bimester','teacher_id_predictor':False,'attendance_used':True,
   'class_context_used':True,'evaluation_dynamics_used':True,'competency_structure_used':True,'zero_weight_evaluations_excluded':True,'requires_official_competency_weight_total_100':True,'legacy_historical_weights_normalized':True,
   'prior_year_history_used':True,'course_historical_context_used':False,'future_data_used':False,
   'validation_group':'stable_student_key'
  }
 }
 out=Path(a.output);out.parent.mkdir(parents=True,exist_ok=True)
 out.write_text(json.dumps(artifact,ensure_ascii=False,indent=2),encoding='utf-8')
 print(f'Modelo v7 creado: {out}')
 print(f'Filas: {len(y)} | estudiantes estables: {len(np.unique(g))} | crítico real: {y.mean():.1%}')
 print(f'Variables activas: {len(features)} / {len(FEATURES)}')
 for k in ['recall','precision','f1','balanced_accuracy','roc_auc','pr_auc','brier_score','ece']:
  v=hold.get(k);print(f'v7_{k}: {v:.3f}' if v is not None else f'v7_{k}: N/D')
 cm=hold['confusion_matrix'];print(f'Holdout operacional @ {a.medium_threshold:.2f}: TN={cm[0][0]} | FP={cm[0][1]} | FN={cm[1][0]} | TP={cm[1][1]}')
 print('Subgrupo crítico persistente: '+json.dumps(persistent,ensure_ascii=False))
 if dropped:print('Variables vacías excluidas: '+', '.join(dropped))

if __name__=='__main__':
 main()
