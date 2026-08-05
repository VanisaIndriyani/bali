-- ============================================================
-- Migration: 2026-08-04 21:00:00 - Add 26 Utility Detail Columns to daily_logs
-- Sesuai catatan kertas user St Regis Bali Engineering Daily Log:
--   ① Listrik (WBP + LWBP)
--   ② Water 9 sumber (PDAM / IKI Gaban / DW 1 / DW2 Brr / ASEAN / LPB / Main Building / Cooling Tower / Bottling)
--   ③ Gas (LPG + LNG)
--   ④ SWRO (Watermeter / kWh / TDS)
--   ⑤ Bottling Water (kWh / Watermeter)
--   ⑥ Chiller System (3 Unit On/Off + pH / TDS / Temp / CHWP Pressure / CWP Pressure)
-- ============================================================

-- ① ELECTRICITY SUB DETAILS (kWh)
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS electricity_wbp DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kWh WBP (Wilayah Beban Puncak)' AFTER total_electricity;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS electricity_lwbp DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kWh LWBP (Luar Wilayah Beban Puncak)' AFTER electricity_wbp;

-- ② WATER SUB DETAILS (m3) - 9 sources
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS water_pdam DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - PDAM' AFTER total_water;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS water_iki_gaban DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - IKI Gaban' AFTER water_pdam;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS water_deepwell_1 DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - Deep Well 1' AFTER water_iki_gaban;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS water_deepwell_2_brr DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - Deep Well 2 Brr' AFTER water_deepwell_1;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS water_deepwell_asean DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - Deep Well ASEAN' AFTER water_deepwell_2_brr;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS water_deepwell_lpb DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - Deep Well LPB' AFTER water_deepwell_asean;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS water_main_building DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - Main Building' AFTER water_deepwell_lpb;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS water_cooling_tower DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - Cooling Tower' AFTER water_main_building;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS water_bottling DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - Bottling Water' AFTER water_cooling_tower;

-- ③ GAS SUB DETAILS (kg)
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS gas_lpg DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kg - LPG' AFTER total_gas;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS gas_lng DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kg - LNG' AFTER gas_lpg;

-- ④ SWRO (Sea Water Reverse Osmosis)
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS swro_watermeter DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - SWRO Water Meter' AFTER gas_lng;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS swro_kwh DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kWh - SWRO Electric' AFTER swro_watermeter;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS swro_tds DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ppm - SWRO TDS' AFTER swro_kwh;

-- ⑤ BOTTLING WATER SECTION
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS bottling_kwh DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'kWh - Bottling Electric' AFTER swro_tds;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS bottling_watermeter DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'm3 - Bottling Water Meter' AFTER bottling_kwh;

-- ⑥ CHILLER SYSTEM (pH 0-14, TDS ppm, Temp C, Pressure bar)
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS chiller_1_on TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=ON 0=OFF Chiller 1 Unit Operation' AFTER bottling_watermeter;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS chiller_2_on TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=ON 0=OFF Chiller 2' AFTER chiller_1_on;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS chiller_3_on TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=ON 0=OFF Chiller 3' AFTER chiller_2_on;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS chiller_water_ph DECIMAL(4,2) NOT NULL DEFAULT 0.00 COMMENT 'pH Chiller Water (0-14)' AFTER chiller_3_on;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS chiller_water_tds DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ppm Chiller Water TDS' AFTER chiller_water_ph;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS chiller_temp DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT 'Celcius Chiller Temperature' AFTER chiller_water_tds;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS chiller_pressure_chwp DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT 'bar/psi CHWP Chiller Hot Water Pump Pressure' AFTER chiller_temp;
ALTER TABLE daily_logs ADD COLUMN IF NOT EXISTS chiller_pressure_cwp DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT 'bar/psi CWP Chilled Water Pump Pressure' AFTER chiller_pressure_chwp;
