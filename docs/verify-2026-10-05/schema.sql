-- 検証用の表定義(php/Kosen_map.sql から CREATE TABLE と ALTER TABLE だけを取り出した。データは含まない)

CREATE TABLE `km_admin_log` (
  `id` int(11) NOT NULL,
  `category` varchar(16) NOT NULL,
  `action` varchar(64) NOT NULL,
  `actor_id` varchar(191) DEFAULT NULL,
  `actor_name` varchar(255) DEFAULT NULL,
  `detail` varchar(500) DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_chat_messages` (
  `id` int(11) NOT NULL,
  `sender_id` varchar(191) NOT NULL,
  `sender_name` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `sent_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_chat_reads` (
  `user_id` varchar(191) NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `last_read_id` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_distributables` (
  `slug` varchar(32) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(64) NOT NULL,
  `size_bytes` bigint(20) UNSIGNED NOT NULL,
  `version_label` varchar(64) DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_events` (
  `id` int(11) NOT NULL,
  `event_date` date NOT NULL,
  `label` varchar(255) NOT NULL,
  `badge_class` varchar(32) NOT NULL DEFAULT 'text-bg-info',
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_faq` (
  `id` int(11) NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer` text NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_files` (
  `id` int(11) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(64) NOT NULL,
  `size_bytes` int(10) UNSIGNED NOT NULL,
  `extension` varchar(16) NOT NULL,
  `uploaded_by` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_form_submissions` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `subject` varchar(32) NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `ip_address` varbinary(16) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_map_edges` (
  `id` int(11) NOT NULL,
  `from_node_id` varchar(32) NOT NULL,
  `to_node_id` varchar(32) NOT NULL,
  `distance` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_map_events` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `hide_occupant_names` tinyint(1) NOT NULL DEFAULT 1,
  `banner_text` varchar(255) DEFAULT NULL,
  `banner_url` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_map_event_aliases` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `node_id` varchar(32) NOT NULL,
  `alias` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_map_event_closures` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `target_type` varchar(8) NOT NULL,
  `from_node_id` varchar(32) DEFAULT NULL,
  `to_node_id` varchar(32) DEFAULT NULL,
  `node_id` varchar(32) DEFAULT NULL,
  `reason` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_map_event_pois` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `floor_id` varchar(16) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(32) NOT NULL DEFAULT 'other',
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `anchor_node_id` varchar(32) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_map_floors` (
  `id` varchar(16) NOT NULL,
  `label` varchar(64) NOT NULL,
  `svg_path` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_map_floor_bounds` (
  `floor_id` varchar(16) NOT NULL,
  `coord_width` decimal(10,2) NOT NULL,
  `coord_height` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_map_nodes` (
  `id` varchar(32) NOT NULL,
  `floor_id` varchar(16) NOT NULL,
  `name` varchar(255) NOT NULL,
  `occupant_name` varchar(255) DEFAULT NULL,
  `type` varchar(32) NOT NULL,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_map_unlock_attempts` (
  `ip_address` varbinary(16) NOT NULL,
  `failure_count` int(11) NOT NULL DEFAULT 0,
  `first_failed_at` datetime NOT NULL,
  `last_failed_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_migrations` (
  `name` varchar(191) NOT NULL,
  `applied_at_epoch` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_table_manage_deleted` (
  `table_name` varchar(41) NOT NULL,
  `deleted_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_tasks` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `status` varchar(16) NOT NULL,
  `progress` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE `km_user_profiles` (
  `user_id` varchar(191) NOT NULL,
  `avatar_stored_name` varchar(64) DEFAULT NULL,
  `avatar_mime` varchar(32) DEFAULT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

ALTER TABLE `km_admin_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `category` (`category`);

ALTER TABLE `km_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sent_at` (`sent_at`);

ALTER TABLE `km_chat_reads`
  ADD PRIMARY KEY (`user_id`);

ALTER TABLE `km_distributables`
  ADD PRIMARY KEY (`slug`);

ALTER TABLE `km_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_date` (`event_date`);

ALTER TABLE `km_faq`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sort_order` (`sort_order`),
  ADD KEY `is_public` (`is_public`);

ALTER TABLE `km_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stored_name` (`stored_name`),
  ADD KEY `created_at` (`created_at`);

ALTER TABLE `km_form_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `is_read` (`is_read`);

ALTER TABLE `km_map_edges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_km_map_edges_from` (`from_node_id`),
  ADD KEY `fk_km_map_edges_to` (`to_node_id`);

ALTER TABLE `km_map_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `is_enabled` (`is_enabled`,`starts_at`,`ends_at`);

ALTER TABLE `km_map_event_aliases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_id` (`event_id`,`node_id`);

ALTER TABLE `km_map_event_closures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`);

ALTER TABLE `km_map_event_pois`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`);

ALTER TABLE `km_map_floors`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `km_map_floor_bounds`
  ADD PRIMARY KEY (`floor_id`);

ALTER TABLE `km_map_nodes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_km_map_nodes_floor` (`floor_id`);

ALTER TABLE `km_map_unlock_attempts`
  ADD PRIMARY KEY (`ip_address`);

ALTER TABLE `km_migrations`
  ADD PRIMARY KEY (`name`);

ALTER TABLE `km_table_manage_deleted`
  ADD PRIMARY KEY (`table_name`);

ALTER TABLE `km_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `sort_order` (`sort_order`);

ALTER TABLE `km_user_profiles`
  ADD PRIMARY KEY (`user_id`);

ALTER TABLE `km_admin_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `km_faq`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_form_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_map_edges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=604;

ALTER TABLE `km_map_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_map_event_aliases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_map_event_closures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_map_event_pois`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `km_map_edges`
  ADD CONSTRAINT `fk_km_map_edges_from` FOREIGN KEY (`from_node_id`) REFERENCES `km_map_nodes` (`id`),
  ADD CONSTRAINT `fk_km_map_edges_to` FOREIGN KEY (`to_node_id`) REFERENCES `km_map_nodes` (`id`);

ALTER TABLE `km_map_floor_bounds`
  ADD CONSTRAINT `fk_km_map_floor_bounds_floor` FOREIGN KEY (`floor_id`) REFERENCES `km_map_floors` (`id`);

ALTER TABLE `km_map_nodes`
  ADD CONSTRAINT `fk_km_map_nodes_floor` FOREIGN KEY (`floor_id`) REFERENCES `km_map_floors` (`id`);
