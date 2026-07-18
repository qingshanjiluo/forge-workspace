-- Forge Workspace - D1 Seed: import users from external MySQL dump
-- All imported users use password "123456" (hashed below with the app's SHA-256 scheme:
--   SHA256("123456" + "forge-workspace-salt-2024") )
-- Password hash (shared by all): 1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414
--
-- INSERT OR IGNORE is used so re-running this script is safe and won't clash with
-- existing usernames (e.g. the built-in admin "zzhx"). IDs auto-assign to avoid
-- colliding with pre-existing rows.

INSERT OR IGNORE INTO users (username, display_name, password, role, created_at) VALUES
('最中幻想', '最中幻想', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'admin', '2026-06-19 17:45:43'),
('miku', 'miku', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:48:12'),
('暗星', '暗星', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:48:24'),
('伶秋', '伶秋', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:48:41'),
('秋枫', '秋枫', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:48:51'),
('灰猎犬号', '灰猎犬号', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:49:05'),
('常闇', '常闇', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:49:32'),
('星空', '星空', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:49:42'),
('圣诞老龙', '圣诞老龙', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:49:49'),
('慕白', '慕白', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:50:05'),
('处刑者', '处刑者', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:50:11'),
('京琼', '京琼', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-19 17:50:19'),
('土豆', '土豆', '1a289d04b37e794e081da43969ecdcc10ac1b844a55398fe42e4141ae0ed0414', 'member', '2026-06-27 22:26:46');
