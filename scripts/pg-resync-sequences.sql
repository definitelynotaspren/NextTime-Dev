-- Moves every auto-increment counter past the highest id already in its table.
-- Counters whose table holds ids larger than the counter can reach (oc_jobs uses generated
-- 64-bit ids and never reads its counter) are left as they are.
DO $$
DECLARE r record;
BEGIN
  FOR r IN
    SELECT s.relname AS seq, t.relname AS tbl, a.attname AS col
    FROM pg_class s
    JOIN pg_depend d ON d.objid = s.oid AND d.deptype IN ('a', 'i')
    JOIN pg_class t ON t.oid = d.refobjid
    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
    WHERE s.relkind = 'S'
  LOOP
    BEGIN
      EXECUTE format('SELECT setval(%L, COALESCE((SELECT MAX(%I) FROM %I), 0) + 1, false)', r.seq, r.col, r.tbl);
    EXCEPTION WHEN numeric_value_out_of_range THEN
      RAISE NOTICE 'left % alone (ids in % are beyond its range)', r.seq, r.tbl;
    END;
  END LOOP;
END $$;
