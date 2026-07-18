#!/usr/bin/env bash

./scripts/a-backtest_comparison.sh &
./scripts/b-backtest_comparison.sh &
./scripts/c-backtest_comparison.sh &
./scripts/d-backtest_comparison.sh &
./scripts/e-backtest_comparison.sh &
./scripts/l-backtest_comparison.sh &

wait
echo "All backtest comparison scripts have completed."