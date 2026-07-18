# V2 vs V4 Training Comparison — 2026-06-13

Generated from logs in `python_ml/v2/training_logs/` and `python_ml/v4/training_logs/`.

## Key Metrics

### Overall (Evaluation Split — BT-simulated only)

| Pipeline | v2 Test AUC | v4 Test AUC | v2 Prec@10 | v4 Prec@10 | AUC Δ | Prec@10 Δ |
|----------|------------|------------|------------|------------|-------|-----------|
| **B** | 0.8299 | 0.8164 | 0.3 | 0.3 | **-0.0135** | 0.0 |
| **E** | 0.6793 | 0.6753 | 0.7 | 0.7 | **-0.0040** | 0.0 |
| **F** | 0.7007 | 0.6957 | 0.8 | 0.8 | **-0.0050** | 0.0 |
| **J** | 0.6301 | 0.6353 | 0.3 | 0.2 | **+0.0052** | **-0.1** |
| **K** | 0.7770 | 0.7740 | 0.9 | 0.7 | **-0.0030** | **-0.2** |
| **L** | 0.6554 | 0.6577 | 0.5 | 0.5 | **+0.0023** | 0.0 |
| **N** | 0.7619 | 0.7575 | 0.9 | 0.9 | **-0.0044** | 0.0 |

### BT-Only Subset

| Pipeline | v2 BT AUC | v4 BT AUC | v2 BT Prec@10 | v4 BT Prec@10 | AUC Δ | Prec@10 Δ |
|----------|----------|----------|--------------|--------------|-------|-----------|
| **B** | 0.8346 | 0.8215 | 0.400 | 0.300 | **-0.0131** | **-0.100** |
| **E** | 0.6820 | 0.6785 | 0.700 | 0.700 | **-0.0035** | 0.0 |
| **F** | 0.7030 | 0.6979 | 0.800 | 0.800 | **-0.0051** | 0.0 |
| **J** | 0.6376 | 0.6431 | 0.300 | 0.200 | **+0.0055** | **-0.100** |
| **K** | 0.7787 | 0.7748 | 0.900 | 0.700 | **-0.0039** | **-0.200** |
| **L** | 0.6629 | 0.6653 | 0.500 | 0.500 | **+0.0024** | 0.0 |
| **N** | 0.7625 | 0.7582 | 0.900 | 0.900 | **-0.0043** | 0.0 |

### Actual-Fill Subset (real Alpaca order data)

| Pipeline | v2 Actual AUC | v4 Actual AUC | v2 Actual Prec@10 | v4 Actual Prec@10 | AUC Δ | Prec@10 Δ |
|----------|-------------|-------------|------------------|------------------|-------|-----------|
| **B** | 0.7059 | 0.5882 | 0.000 | 0.000 | **-0.1177** | 0.0 |
| **E** | — (2 rows) | — (2 rows) | — | — | — | — |
| **F** | 0.1429 | 0.1429 | 0.125 | 0.125 | 0.0 | 0.0 |
| **J** | 0.1957 | 0.2065 | 0.000 | 0.000 | **+0.0108** | 0.0 |
| **K** | 0.7605 | 0.7878 | 0.500 | 0.500 | **+0.0273** | 0.0 |
| **L** | 0.9688 | 0.9375 | 0.100 | 0.100 | **-0.0313** | 0.0 |
| **N** | — (2 rows) | — (2 rows) | — | — | — | — |

## Summary

### Overall Trend
**v4 is slightly worse overall.** 5 of 7 pipelines show a small test AUC decline (-0.003 to -0.014). The decline is most pronounced in Pipeline B (-0.0135) and in the actual-fill subset for B (-0.1177 AUC).

### Precision@10 Decline
Pipeline **K** and **J** lost Precision@10 (-0.2 and -0.1 respectively on the BT-only subset), which is significant for top-alert selection.

### Bright Spots
- Pipeline **J** and **L** gained slightly on test AUC (+0.005 and +0.002)
- Pipeline **K** gained on actual-fill AUC (+0.027)
- Precision@10 held steady for B, E, F, L, N

### Verdict
**v2 is the better version** based on this comparison. v4 underperforms across most pipelines, particularly on Pipeline B (the highest-volume pipeline by rows) and on actual-fill metrics for B. The Precision@10 degradation on K and J is concerning.

Recommend sticking with v2 models for production.
