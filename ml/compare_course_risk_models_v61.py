#!/usr/bin/env python3
"""Compara v6 logística vs candidatos no lineales en el MISMO holdout temporal.

No activa ningún modelo. El umbral operativo de cada candidato se selecciona
solo con OOF agrupado del entrenamiento para intentar Recall >= objetivo.
"""
from __future__ import annotations
import argparse, math
import numpy as np
from sklearn.ensemble import RandomForestClassifier, ExtraTreesClassifier, HistGradientBoostingClassifier
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import GroupKFold
from sklearn.preprocessing import StandardScaler
from sklearn.utils.class_weight import compute_sample_weight
import train_course_risk_model as core


def cli():
    p=argparse.ArgumentParser()
    p.add_argument('--input',required=True)
    p.add_argument('--target-recall',type=float,default=.80)
    p.add_argument('--folds',type=int,default=5)
    p.add_argument('--seed',type=int,default=42)
    return p.parse_args()


def choose_threshold(y,p,target_recall):
    rows=[]
    for t in np.arange(.05,.601,.01):
        m=core.metrics(y,p,float(t))
        rows.append((float(t),m))
    feasible=[x for x in rows if x[1]['recall']>=target_recall]
    if feasible:
        return max(feasible,key=lambda x:(x[1]['precision'],x[1]['f1'],x[0]))
    return max(rows,key=lambda x:(x[1]['recall'],x[1]['f1']))


def preprocess_fit(X):
    imp=SimpleImputer(strategy='median',add_indicator=True)
    return imp,imp.fit_transform(X)


def base_model(name,seed):
    if name=='logistic':
        return LogisticRegression(class_weight='balanced',max_iter=4000,random_state=seed)
    if name=='random_forest':
        return RandomForestClassifier(n_estimators=500,max_depth=None,min_samples_leaf=6,max_features='sqrt',class_weight='balanced_subsample',n_jobs=-1,random_state=seed)
    if name=='extra_trees':
        return ExtraTreesClassifier(n_estimators=500,max_depth=None,min_samples_leaf=6,max_features='sqrt',class_weight='balanced',n_jobs=-1,random_state=seed)
    if name=='hist_gradient_boosting':
        return HistGradientBoostingClassifier(max_iter=300,learning_rate=.05,max_leaf_nodes=15,min_samples_leaf=20,l2_regularization=1.0,random_state=seed)
    raise ValueError(name)


def fit_predict_base(name,Xtr,ytr,Xva,seed):
    imp,Xi=preprocess_fit(Xtr);Xv=imp.transform(Xva)
    if name=='logistic':
        sc=StandardScaler();Xi=sc.fit_transform(Xi);Xv=sc.transform(Xv)
        model=base_model(name,seed);model.fit(Xi,ytr)
    elif name=='hist_gradient_boosting':
        model=base_model(name,seed);w=compute_sample_weight('balanced',ytr);model.fit(Xi,ytr,sample_weight=w)
    else:
        model=base_model(name,seed);model.fit(Xi,ytr)
    return np.clip(model.predict_proba(Xv)[:,1],1e-6,1-1e-6)


def fit_platt(rawp,y):
    z=np.log(np.clip(rawp,1e-6,1-1e-6)/(1-np.clip(rawp,1e-6,1-1e-6)))
    cal=LogisticRegression(C=1e6,solver='lbfgs',max_iter=2000)
    cal.fit(z.reshape(-1,1),y)
    coef=float(cal.coef_[0,0]);inter=float(cal.intercept_[0])
    if not math.isfinite(coef) or coef<=0: raise RuntimeError('calibración inválida')
    return coef,inter


def apply_platt(rawp,coef,inter):
    z=np.log(np.clip(rawp,1e-6,1-1e-6)/(1-np.clip(rawp,1e-6,1-1e-6)))
    return core.sigmoid(inter+coef*z)


def evaluate(name,X,y,g,tr,te,target_recall,folds,seed):
    k=min(max(2,folds),len(np.unique(g[tr])))
    oof=np.full(len(tr),np.nan)
    local=np.arange(len(tr))
    for no,(itr,iva) in enumerate(GroupKFold(n_splits=k).split(X[tr],y[tr],groups=g[tr]),1):
        if len(np.unique(y[tr][itr]))<2: continue
        oof[iva]=fit_predict_base(name,X[tr][itr],y[tr][itr],X[tr][iva],seed+no)
    mask=np.isfinite(oof)
    if mask.sum()<100 or len(np.unique(y[tr][mask]))<2: return None
    cc,ci=fit_platt(oof[mask],y[tr][mask]);poof=apply_platt(oof[mask],cc,ci)
    threshold,trainm=choose_threshold(y[tr][mask],poof,target_recall)
    rawtest=fit_predict_base(name,X[tr],y[tr],X[te],seed+1000)
    ptest=apply_platt(rawtest,cc,ci);testm=core.metrics(y[te],ptest,threshold)
    return threshold,trainm,testm


def main():
    a=cli();X0,y,g,raw=core.load(a.input)
    active=[i for i in range(len(core.FEATURES)) if not np.all(np.isnan(X0[:,i]))]
    X=X0[:,active]
    periods={}
    for i,r in enumerate(raw):
        key=(str(r['cutoff_date']),int(float(r['academic_year_id'])),int(float(r['bimester'])),int(float(r['target_bimester'])))
        periods.setdefault(key,[]).append(i)
    latest=None
    for key,testlist in sorted(periods.items(),key=lambda kv:kv[0]):
        cutoff=key[0];tr=np.asarray([i for i,r in enumerate(raw) if str(r['cutoff_date'])<cutoff],int);te=np.asarray(testlist,int)
        if len(tr)>=50 and len(np.unique(y[tr]))==2 and len(np.unique(y[te]))==2: latest=(key,tr,te)
    if latest is None: raise SystemExit('No existe holdout temporal válido.')
    key,tr,te=latest;cutoff,year,base,target=key
    print(f'Holdout común: año {year} | {base}->{target} | corte {cutoff} | train {len(tr)} | test {len(te)}')
    print(f'Meta operativa seleccionada SOLO con entrenamiento: Recall >= {a.target_recall:.0%}')
    print('Modelo | umbral | recall | precision | f1 | PR-AUC | ROC-AUC | Brier | ECE | FP | FN')
    for name in ['logistic','random_forest','extra_trees','hist_gradient_boosting']:
        try:r=evaluate(name,X,y,g,tr,te,a.target_recall,a.folds,a.seed)
        except Exception as e:
            print(f'{name} | ERROR: {e}');continue
        if r is None:
            print(f'{name} | SIN RESULTADO');continue
        threshold,trainm,m=r;cm=m['confusion_matrix']
        print(f'{name} | {threshold:.2f} | {m["recall"]:.3f} | {m["precision"]:.3f} | {m["f1"]:.3f} | {m["pr_auc"]:.3f} | {m["roc_auc"]:.3f} | {m["brier_score"]:.3f} | {m["ece"]:.3f} | {cm[0][1]} | {cm[1][0]}')
        print(f'  entrenamiento OOF: recall={trainm["recall"]:.3f} precision={trainm["precision"]:.3f} f1={trainm["f1"]:.3f}')

if __name__=='__main__': main()
