/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_audit_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `audit_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_identity` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `before_state` longtext COLLATE utf8mb4_unicode_ci,
  `after_state` longtext COLLATE utf8mb4_unicode_ci,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `occurred_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_audit_events_audit_id_unique` (`audit_id`),
  KEY `realtime_audit_events_actor_user_id_foreign` (`actor_user_id`),
  CONSTRAINT `realtime_audit_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_client_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_client_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `assignment_role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_client_user_client_id_user_id_unique` (`client_id`,`user_id`),
  KEY `realtime_client_user_user_id_foreign` (`user_id`),
  CONSTRAINT `realtime_client_user_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `realtime_clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `realtime_client_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `description` text COLLATE utf8mb4_unicode_ci,
  `integration_owner` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `integration_notes` text COLLATE utf8mb4_unicode_ci,
  `issuer_identity` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_issuance_mode` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'app_backend_signed',
  `trusted_signing_profile` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `backend_ingress_secret_hash` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `backend_ingress_secret_digest` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trust_notes` text COLLATE utf8mb4_unicode_ci,
  `allowed_origins` text COLLATE utf8mb4_unicode_ci,
  `origin_policy_mode` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'allowlist',
  `policy_profile_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capability_profile_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `room_policy_profile_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `last_reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `last_reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_clients_client_code_unique` (`client_code`),
  KEY `realtime_clients_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `realtime_clients_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `realtime_clients_last_reviewed_by_user_id_foreign` (`last_reviewed_by_user_id`),
  CONSTRAINT `realtime_clients_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `realtime_clients_last_reviewed_by_user_id_foreign` FOREIGN KEY (`last_reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `realtime_clients_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_media_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_media_chunks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chunk_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `room` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int(10) unsigned NOT NULL DEFAULT '0',
  `payload` json NOT NULL,
  `meta` json DEFAULT NULL,
  `queued_at` timestamp NULL DEFAULT NULL,
  `forwarded_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `failure_reason` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `downstream_status` smallint(5) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_media_chunks_chunk_id_unique` (`chunk_id`),
  KEY `realtime_media_chunks_client_code_index` (`client_code`),
  KEY `realtime_media_chunks_project_code_index` (`project_code`),
  KEY `realtime_media_chunks_session_id_index` (`session_id`),
  KEY `realtime_media_chunks_status_index` (`status`),
  KEY `realtime_media_chunks_status_id_index` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `policy_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `description` text COLLATE utf8mb4_unicode_ci,
  `policy_category` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_team` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capability_profile` text COLLATE utf8mb4_unicode_ci,
  `room_policy_profile` text COLLATE utf8mb4_unicode_ci,
  `rate_limit_profile` text COLLATE utf8mb4_unicode_ci,
  `session_limit_profile` text COLLATE utf8mb4_unicode_ci,
  `allow_deny_mode` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'allowlist',
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_policies_policy_code_unique` (`policy_code`),
  KEY `realtime_policies_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `realtime_policies_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `realtime_policies_client_id_foreign` (`client_id`),
  CONSTRAINT `realtime_policies_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `realtime_clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `realtime_policies_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `realtime_policies_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) unsigned NOT NULL,
  `project_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `description` text COLLATE utf8mb4_unicode_ci,
  `scope_notes` text COLLATE utf8mb4_unicode_ci,
  `allowed_origins` text COLLATE utf8mb4_unicode_ci,
  `media_ingest_settings` text COLLATE utf8mb4_unicode_ci,
  `product_query_forwarding_settings` text COLLATE utf8mb4_unicode_ci,
  `origin_policy_mode` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'allowlist',
  `policy_profile_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capability_profile_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `room_policy_profile_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `last_reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `last_reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_projects_project_code_unique` (`project_code`),
  KEY `realtime_projects_client_id_foreign` (`client_id`),
  KEY `realtime_projects_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `realtime_projects_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `realtime_projects_last_reviewed_by_user_id_foreign` (`last_reviewed_by_user_id`),
  CONSTRAINT `realtime_projects_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `realtime_clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `realtime_projects_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `realtime_projects_last_reviewed_by_user_id_foreign` FOREIGN KEY (`last_reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `realtime_projects_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_runtime_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_runtime_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_runtime_settings_setting_key_unique` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_server_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_server_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `publish_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `room` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int(10) unsigned NOT NULL DEFAULT '0',
  `payload` json NOT NULL,
  `meta` json DEFAULT NULL,
  `fanout_count` int(10) unsigned NOT NULL DEFAULT '0',
  `queued_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `failure_reason` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_server_events_publish_id_unique` (`publish_id`),
  KEY `realtime_server_events_client_code_project_code_index` (`client_code`,`project_code`),
  KEY `realtime_server_events_room_status_index` (`room`,`status`),
  KEY `realtime_server_events_status_index` (`status`),
  KEY `realtime_server_events_client_code_created_at_index` (`client_code`,`created_at`),
  KEY `realtime_server_events_status_id_index` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_code` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `app_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_identity` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'connected',
  `connected_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `disconnect_reason` text COLLATE utf8mb4_unicode_ci,
  `room_count` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_sessions_session_id_unique` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `realtime_usage_buckets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `realtime_usage_buckets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bucket_start` datetime NOT NULL,
  `bucket_granularity` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `project_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `event_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `bytes_in` bigint(20) unsigned NOT NULL DEFAULT '0',
  `bytes_out` bigint(20) unsigned NOT NULL DEFAULT '0',
  `error_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `rate_limited_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `realtime_usage_buckets_unique_bucket` (`bucket_start`,`bucket_granularity`,`client_code`,`project_code`,`event_type`),
  KEY `realtime_usage_buckets_bucket_start_bucket_granularity_index` (`bucket_start`,`bucket_granularity`),
  KEY `realtime_usage_buckets_client_code_index` (`client_code`),
  KEY `realtime_usage_buckets_project_code_index` (`project_code`),
  KEY `realtime_usage_buckets_event_type_index` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_operator` tinyint(1) NOT NULL DEFAULT '0',
  `user_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'regular',
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2014_10_12_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2014_10_12_100000_create_password_reset_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2019_08_19_000000_create_failed_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_03_28_000001_add_is_operator_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_03_28_000100_create_realtime_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_03_28_000110_create_realtime_policies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_03_28_000120_create_realtime_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_03_28_000130_create_realtime_audit_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_03_29_000100_create_realtime_projects_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_03_30_000100_backfill_generated_client_codes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_03_30_000200_backfill_generated_project_and_policy_codes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_03_31_000300_create_realtime_usage_buckets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_03_31_000400_add_client_id_to_realtime_policies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_04_02_000100_add_user_type_to_users_and_create_realtime_client_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_04_02_001200_add_display_name_to_realtime_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_04_06_141500_add_backend_ingress_secret_hash_to_realtime_clients_and_create_realtime_server_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_04_07_040000_create_realtime_runtime_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_04_10_020000_add_client_code_created_at_index_to_realtime_server_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_04_10_030000_add_backend_ingress_secret_digest_to_realtime_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_04_16_000200_add_media_ingest_settings_to_realtime_projects_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_04_17_170000_create_realtime_media_chunks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_04_23_130000_add_realtime_queue_dispatch_indexes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_05_11_160000_add_product_query_forwarding_settings_to_realtime_projects_table',1);
