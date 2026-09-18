#!/usr/bin/env python3
"""Selecciona un umbral operativo v6 sin mirar el holdout temporal final.

La selección se hace SOLO con el conjunto de entrenamiento anterior al último
corte temporal, usando predicciones OOF agrupadas por estudiante. Luego se
aplica el umbral elegido al holdout temporal final para medir su comportamiento.
"""
from __future__ import annotations
import argparse, math
import numpy as np
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import GroupKFold
import train_course_risk_model as core


def args():
    p=argparse.ArgumentParser()
    p.add_argument('--input',required=True)
    p.add_argument('--target-recall',type=float,default=.80)
    p.add_argument('--calibration-folds',type=int,default=5)
    p.add_argument('--seed',type=int,default=42)
    return p.parse_args()


def oof_calibrated_probs(X,y,g,seed,folds):
    k=min(max(2,folds),len(np.unique(g)))
    raw=np.full(len(y),np.nan)
    for no,(tr,va) in enumerate(GroupKFold(n_splits=k).split(X,y,groups=g),1):
        if len(np.unique(y[tr]))<2: continue
        imp,sc,m=core.fit_base(X[tr],y[tr],seed+no)
        raw[va]=core.decision(X[va],imp,sc,m)
    mask=np.isfinite(raw)
    if mask.sum()<50 or len(np.unique(y[mask]))<2:
        raise SystemExit('No hay suficientes predicciones OOF para seleccionar umbral.')
    cal=LogisticRegression(C=1e6,solver='lbfgs',max_iter=2000)
    cal.fit(raw[mask].reshape(-1,1),y[mask])
    coef=float(cal.coef_[0,0]);inter=float(cal.intercept_[0])
    if not math.isfinite(coef) or coef<=0:
        raise SystemExit('Calibración OOF inválida.')
    p=core.sigmoid(inter+coef*raw[mask])
    return y[mask],p


def choose_threshold(y,p,target_recall):
    candidates=[]
    for t in np.arange(.05,.601,.01):
        m=core.metrics(y,p,float(t))
        candidates.append((float(t),m))
    feasible=[x for x in candidates if x[1]['recall']>=target_recall]
    if feasible:
        # Entre los que cumplen recall, preferir mayor precision; desempate por F1.
        best=max(feasible,key=lambda x:(x[1]['precision'],x[1]['f1'],x[0]))
    else:
        best=max(candidates,key=lambda x:(x[1]['recall'],x[1]['f1']))
    return best,candidates


def main():
    a=args();X0,y,g,raw=core.load(a.input)
    idx=[i for i in range(len(core.FEATURES)) if not np.all(np.isnan(X0[:,i]))]
    X=X0[:,idx]

    periods={}
    for i,r in enumerate(raw):
        key=(str(r['cutoff_date']),int(float(r['academic_year_id'])),int(float(r['bimester'])),int(float(r['target_bimester'])))
        periods.setdefault(key,[]).append(i)
    ordered=sorted(periods.items(),key=lambda kv:kv[0])
    latest=None
    for key,testlist in ordered:
        cutoff=key[0]
        tr=np.asarray([i for i,r in enumerate(raw) if str(r['cutoff_date'])<cutoff],int)
        te=np.asarray(testlist,int)
        if len(tr)>=50 and len(np.unique(y[tr]))==2 and len(np.unique(y[te]))==2:
            latest=(key,tr,te)
    if latest is None: raise SystemExit('No existe holdout temporal válido.')
    key,tr,te=latest;cutoff,year,base,target=key

    yoof,poof=oof_calibrated_probs(X[tr],y[tr],g[tr],a.seed,a.calibration_folds)
    (threshold,train_metrics),candidates=choose_threshold(yoof,poof,a.target_recall)

    imp,sc,m,cc,ci,_,_,_=core.fit_calibrated(X[tr],y[tr],g[tr],a.seed+1000,a.calibration_folds)
    ptest=core.sigmoid(ci+cc*core.decision(X[te],imp,sc,m))
    test_metrics=core.metrics(y[te],ptest,threshold)
    cm=test_metrics['confusion_matrix']

    print(f'Holdout final: año {year} | {base}->{target} | corte {cutoff} | train {len(tr)} | test {len(te)}')
    print(f'Objetivo de selección en entrenamiento: Recall >= {a.target_recall:.0%}')
    print(f'Umbral seleccionado SOLO con entrenamiento: {threshold:.2f}')
    print('--- OOF entrenamiento al umbral seleccionado ---')
    for k in ['recall','precision','f1','balanced_accuracy']:
        print(f'train_{k}: {train_metrics[k]:.3f}')
    print('--- Holdout temporal final ---')
    for k in ['recall','precision','f1','balanced_accuracy','roc_auc','pr_auc','brier_score','ece']:
        v=test_metrics.get(k); print(f'temporal_{k}: {v:.3f}' if v is not None else f'temporal_{k}: N/D')
    print(f'TN={cm[0][0]} | FP={cm[0][1]} | FN={cm[1][0]} | TP={cm[1][1]}')
    print('--- Tabla de referencia en el holdout (NO usada para seleccionar) ---')
    for t in [.15,.20,.25,.30,.35,.40,.45,.50]:
        mm=core.metrics(y[te],ptest,t);c=mm['confusion_matrix']
        print(f'{t:.2f} | recall={mm["recall"]:.3f} precision={mm["precision"]:.3f} f1={mm["f1"]:.3f} FP={c[0][1]} FN={c[1][0]}')

if __name__=='__main__':main()
