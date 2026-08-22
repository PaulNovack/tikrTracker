DELETE ta
FROM trade_alerts AS ta
where NOT EXISTS (
      SELECT 1
      FROM alpaca_orders AS ao
      WHERE ao.trade_alert_id = ta.id
  )
  


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
    WHERE row_num > 20000
) AS old_records
    ON old_records.id = t.id;



    select avv.pipeline_letter,avvg.* from alert_versions avv
inner join alert_version_gates avvg on (avvg.alert_version_id = avv.id)
where pipeline_letter = 'I'