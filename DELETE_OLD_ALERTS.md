# Delete Old Alerts

Use this SQL to prune old `trade_alerts` records, keeping only the most recent 15,000 per `pipeline_run`.

```sql
DELETE t
FROM trade_alerts AS t
JOIN (
    SELECT id
    FROM (
        SELECT
            id,
            ROW_NUMBER() OVER (
                PARTITION BY pipeline_run
                ORDER BY id DESC
            ) AS row_num
        FROM trade_alerts
    ) AS ranked
    WHERE row_num > 15000
) AS old_records
    ON old_records.id = t.id;
```
Delete all not actually traded in alpaca

```sql
DELETE FROM trade_alerts AS ta
  AND NOT EXISTS (
      SELECT 1
      FROM alpaca_orders AS ao
      WHERE ao.trade_alert_id = ta.id
  );
  ```