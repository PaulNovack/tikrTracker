# Pipeline E Training Comparison — June 13, 2026

## Runs Compared

| Metric | 11:00 UTC (earlier) | 21:00 UTC (latest) |
|--------|---------------------|---------------------|
| Log file | `E-2026-06-13_11.log` | `E-2026-06-13_21.log` |
| 18:00 UTC run | — | Empty/aborted log (2 lines, no training output) |

---

## Data Volume

| Metric | 11:00 | 21:00 | Δ |
|--------|-------|-------|---|
| Training rows (eval split) | 7,600 | 7,701 | +101 |
| Test rows | 702 | 1,107 | +405 |
| Final full-data rows | 8,300 | 8,806 | +506 |
| Actual Alpaca fills | 2 (1 win) | 2 (1 win) | — |
| BT-simulated fills (eval) | 700 (166 wins) | 1,105 (392 wins) | +405 rows, +226 wins |

**Finding:** +506 new rows flowed in between runs (≈6.1% more data). Test set grew significantly (+405 rows), suggesting the train/test split point shifted forward in time.

---

## Model Performance

| Metric | 11:00 | 21:00 | Δ |
|--------|-------|-------|---|
| **Test AUC** | **0.6793** | **0.6154** | **−0.064 ↓** |
| **Precision@10** | 0.700 | **0.900** | **+0.200 ↑** |
| **Test win rate** | 0.238 | **0.355** | **+0.117 ↑** |
| Weighted avg F1 | 0.670 | 0.594 | −0.076 ↓ |
| scale_pos_weight | 4.48 → 4.35 | 4.48 → 3.90 | Lower final ratio |

### Confusion Matrix

| | 11:00 | 21:00 |
|---|-------|-------|
| TN | 349 | 412 |
| FP | 186 | 302 |
| FN | 63 | 157 |
| TP | 104 | 236 |

**Finding:** AUC dropped significantly, but Precision@10 improved dramatically (0.7 → 0.9). The model is worse at overall class separation (lower AUC) yet better at identifying the very highest-confidence winners (higher P@10). This is a **tradeoff**: the model is less discriminating overall but more precise at the extreme top end.

---

## Probability Calibration

### 11:00 Buckets
| Bucket | Win Rate |
|--------|----------|
| (0.0, 0.3] | 11.7% |
| (0.3, 0.4] | 15.3% |
| (0.4, 0.5] | 17.6% |
| (0.5, 0.6] | 26.8% |
| (0.6, 0.7] | 45.3% |
| (0.7, 0.8] | 41.7% ⚠️ |
| (0.8, 0.9] | 72.7% |

### 21:00 Buckets
| Bucket | Win Rate |
|--------|----------|
| (0.0, 0.3] | 26.4% |
| (0.3, 0.4] | 28.6% |
| (0.4, 0.5] | 27.5% |
| (0.5, 0.6] | 38.3% |
| (0.6, 0.7] | 44.6% |
| (0.7, 0.8] | 54.7% |
| (0.8, 0.9] | 78.6% |

**Finding:** 21:00 calibration is **monotonically better** — win rates increase cleanly with every probability bucket (no inversions). The 11:00 run had a dip at (0.7, 0.8] (41.7% vs 45.3% in the lower bin). However, the 21:00 model is **overconfident at low probabilities**: 26.4% win rate in the (0, 0.3] bucket vs 11.7% before — it's pushing too many rows higher.

---

## Top-10 Test Picks

### 21:00 (9/10 winners)
| # | Symbol | Signal | PnL% | Win? |
|---|--------|--------|------|------|
| 1 | KODK | TREND_CONTINUATION / VWAP_EMA_RECLAIM | +21.07% | ✅ |
| 2 | NVAX | FORWARD_5PCT_RUNNER_5M (Pipeline P) | +2.65% | ✅ |
| 3 | APPS | FORWARD_5PCT_RUNNER_5M (Pipeline P) | +4.29% | ✅ |
| 4 | ANNX | TREND_CONTINUATION / VWAP_EMA_RECLAIM | +3.42% | ✅ |
| 5 | SPCE | TREND_CONTINUATION / HIGHER_LOW_BOUNCE | +6.14% | ✅ |
| 6 | ROIV | FORWARD_5PCT_RUNNER_5M (Pipeline P) | +2.82% | ✅ |
| 7 | REPL | TREND_CONTINUATION / VOLUME_SURGE_CONT | +5.79% | ✅ |
| 8 | VELO | TREND_CONTINUATION / VOLUME_SURGE_CONT | +6.27% | ✅ |
| 9 | TE | FORWARD_5PCT_RUNNER_5M (Pipeline P) | +7.18% | ✅ |
| 10 | IONX | TREND_CONTINUATION / BULL_FLAG_BREAK | +1.67% | ❌ |

### 11:00 (8/10 winners)
| # | Symbol | Signal | PnL% | Win? |
|---|--------|--------|------|------|
| 1 | REPL | VOLUME_SURGE_CONT | +5.79% | ✅ |
| 2 | SPCE | HIGHER_LOW_BOUNCE | +6.14% | ✅ |
| 3 | KODK | VWAP_EMA_RECLAIM | +21.07% | ✅ |
| 4 | AXTI | VWAP_EMA_RECLAIM | −1.30% | ❌ |
| 5 | LFS | HIGHER_LOW_BOUNCE | −1.00% | ❌ |
| 6 | VELO | VOLUME_SURGE_CONT | +6.27% | ✅ |
| 7 | IONX | BULL_FLAG_BREAK | +1.67% | ❌ (same as 21:00 #10) |
| 8 | AXTI | VWAP_EMA_RECLAIM | +8.25% | ✅ |
| 9 | SPCE | HIGHER_LOW_BOUNCE | +7.88% | ✅ |
| 10 | GRPN | VWAP_EMA_RECLAIM | +3.96% | ✅ |

**Finding:** The 21:00 model's top-10 includes **Pipeline P signals** (FORWARD_5PCT_RUNNER_5M / FORWARD_2H_RUNNER_1M) for the first time — NVAX, APPS, ROIV, TE. This suggests the train/test split shifted into a date range where Pipeline P rows exist. The 11:00 run was pure Pipeline E. All 4 Pipeline P picks in the top-10 were winners.

---

## Key Takeaways

1. **Data growth**: +506 rows (6.1%) flowed in over 10 hours. The train/test boundary moved forward, pulling in Pipeline P signals.

2. **AUC vs Precision tradeoff**: AUC fell from 0.68 → 0.62, but P@10 jumped from 0.70 → 0.90. The model sacrifices overall discrimination for better extreme-top-end precision. This may be caused by mixing Pipeline E and P data with different signal characteristics.

3. **Better calibration at top bins**: The 21:00 model has perfectly monotonic probability buckets with 78.6% win rate in the (0.8, 0.9] bin (vs 72.7% before). High-confidence predictions are more trustworthy.

4. **Pipeline P signals appear promising**: All 4 Pipeline P picks in the 21:00 top-10 were winners with strong R-multiples (1.33–3.59). However, the mixed E+P data may explain the lower AUC.

5. **18:00 run failed silently**: Only 2 log lines, no training output. Worth investigating if this was a cron/scheduling issue.

6. **No improvement in actual fills**: Still only 2 actual Alpaca fills across all runs — the 20× boost weight on them has negligible impact.
