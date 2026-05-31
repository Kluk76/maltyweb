-- ============================================================================
-- Migration 239: Packaging cuve-de-service liner MI foreign keys
--
-- WHAT:
--   Adds two nullable FK columns to bd_packaging_v2 that record WHICH liner MI
--   was installed in each cuve-de-service event:
--     - liner_client_mi_id_fk   INT UNSIGNED NULL → ref_mi(id)
--     - liner_transport_mi_id_fk INT UNSIGNED NULL → ref_mi(id)
--   Corresponding indexes added (MySQL requires an index for every FK column).
--
--   The existing TINYINT bool columns (new_liner_client / new_liner_transport)
--   are KEPT unchanged — they are forward-only historical provenance for the
--   107 client / 97 transport legacy rows where a liner was installed but the
--   specific MI is unknown.  New form submissions write the FK and leave the
--   bool NULL.  bool-set vs FK-set cleanly discriminates legacy vs new rows.
--
-- WHY:
--   The Oui/Non booleans captured only "was a liner installed?" — not which
--   liner type.  Given that 5HL and 10HL tanks use different liner MIs (and
--   some tanks use DSSP Metal), the specific product is COGS-relevant once the
--   packaging-consumption pipeline is wired.  This migration captures the MI
--   identity per event so the consumption arc (deferred) has a clean FK to
--   walk.
--
-- ROLLBACK:
--   ALTER TABLE bd_packaging_v2
--     DROP FOREIGN KEY fk_bdpv2_liner_transport_mi,
--     DROP FOREIGN KEY fk_bdpv2_liner_client_mi,
--     DROP KEY idx_bdpv2_liner_transport_mi,
--     DROP KEY idx_bdpv2_liner_client_mi,
--     DROP COLUMN liner_transport_mi_id_fk,
--     DROP COLUMN liner_client_mi_id_fk;
-- ============================================================================

-- 1. Add liner_client_mi_id_fk (MySQL 8: no IF NOT EXISTS on ADD COLUMN)
ALTER TABLE bd_packaging_v2
  ADD COLUMN liner_client_mi_id_fk INT UNSIGNED NULL
    COMMENT 'FK to ref_mi: specific liner MI installed for the client cuve in this event. NULL = no liner or legacy bool row (see new_liner_client). New form writes FK; bool left NULL for new rows.'
    AFTER new_liner_client;

-- 2. Add liner_transport_mi_id_fk
ALTER TABLE bd_packaging_v2
  ADD COLUMN liner_transport_mi_id_fk INT UNSIGNED NULL
    COMMENT 'FK to ref_mi: specific liner MI installed for the transport cuve in this event. NULL = no liner or legacy bool row (see new_liner_transport). New form writes FK; bool left NULL for new rows.'
    AFTER new_liner_transport;

-- 3. Indexes (MySQL requires an index backing each FK; also used for future JOIN lookups)
ALTER TABLE bd_packaging_v2
  ADD KEY idx_bdpv2_liner_client_mi (liner_client_mi_id_fk),
  ADD KEY idx_bdpv2_liner_transport_mi (liner_transport_mi_id_fk);

-- 4. Foreign key constraints
ALTER TABLE bd_packaging_v2
  ADD CONSTRAINT fk_bdpv2_liner_client_mi
    FOREIGN KEY (liner_client_mi_id_fk)
    REFERENCES ref_mi(id)
    ON DELETE RESTRICT,
  ADD CONSTRAINT fk_bdpv2_liner_transport_mi
    FOREIGN KEY (liner_transport_mi_id_fk)
    REFERENCES ref_mi(id)
    ON DELETE RESTRICT;
