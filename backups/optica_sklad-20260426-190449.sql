/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.6.23-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: 127.0.0.1    Database: optica_sklad
-- ------------------------------------------------------
-- Server version	10.6.23-MariaDB-0ubuntu0.22.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `app_user`
--

DROP TABLE IF EXISTS `app_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(180) DEFAULT NULL,
  `roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`roles`)),
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `username` varchar(180) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_88BDF3E9F85E0677` (`username`),
  UNIQUE KEY `UNIQ_88BDF3E9E7927C74` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_user`
--

LOCK TABLES `app_user` WRITE;
/*!40000 ALTER TABLE `app_user` DISABLE KEYS */;
INSERT INTO `app_user` VALUES (4,'petr@example.com','[]','$2y$13$V3DERvmukOl26kibCSE6geuJRBzcvpiv76FYqhNz6Ib7WlVIWndeW',1,'2026-04-25 10:14:02','2026-04-25 10:14:02','petr.ivanov','Petr','Ivanov');
/*!40000 ALTER TABLE `app_user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cable_family`
--

DROP TABLE IF EXISTS `cable_family`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cable_family` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `label` varchar(128) NOT NULL,
  `description` longtext DEFAULT NULL,
  `sort_order` int(11) NOT NULL,
  `is_active` tinyint(4) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_CABLE_FAMILY_CODE` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cable_family`
--

LOCK TABLES `cable_family` WRITE;
/*!40000 ALTER TABLE `cable_family` DISABLE KEYS */;
INSERT INTO `cable_family` VALUES (1,'blown','blown (ofuk)','Konstrukce typu blown; podrobný popis můžete doplňovat v administraci.',10,1,'2026-04-26 12:26:32'),(2,'mlt','mlt (multi-loose-tube)',NULL,20,1,'2026-04-26 12:26:32'),(3,'drop','drop (drop kabel)',NULL,30,1,'2026-04-26 12:26:32'),(4,'fletka','fletka (flat drop)',NULL,40,1,'2026-04-26 12:26:32');
/*!40000 ALTER TABLE `cable_family` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cable_spool`
--

DROP TABLE IF EXISTS `cable_spool`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cable_spool` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reel_number` varchar(128) NOT NULL,
  `family` varchar(32) NOT NULL,
  `total_length_m` int(11) NOT NULL,
  `initial_visible_m` int(11) NOT NULL,
  `current_remaining_m` int(11) DEFAULT NULL,
  `last_visible_m` int(11) DEFAULT NULL,
  `meter_sign` int(11) DEFAULT NULL,
  `fiber_count` int(11) DEFAULT NULL,
  `diameter_mm` decimal(4,1) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `reserved_m` int(11) DEFAULT NULL,
  `note` longtext DEFAULT NULL,
  `registered_at` date NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `cable_type_id` int(11) DEFAULT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `updated_by_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_7DB407A89978C35C` (`reel_number`),
  KEY `IDX_7DB407A8E0B6D1E` (`cable_type_id`),
  KEY `IDX_7DB407A8B03A8386` (`created_by_id`),
  KEY `IDX_7DB407A8896DBBDE` (`updated_by_id`),
  KEY `idx_spool_reel` (`reel_number`),
  CONSTRAINT `FK_7DB407A8896DBBDE` FOREIGN KEY (`updated_by_id`) REFERENCES `app_user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_7DB407A8B03A8386` FOREIGN KEY (`created_by_id`) REFERENCES `app_user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_7DB407A8E0B6D1E` FOREIGN KEY (`cable_type_id`) REFERENCES `cable_type` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cable_spool`
--

LOCK TABLES `cable_spool` WRITE;
/*!40000 ALTER TABLE `cable_spool` DISABLE KEYS */;
INSERT INTO `cable_spool` VALUES (2,'450-1851','blown',2095,4209,1771,0,-1,2,2.0,'in_stock',0,'Potvrzeno štítkem KDP 2025-09-09, délka 2095 m, foto Drum-6 a Drum-7 (stejná cívka)','2026-04-25','2026-04-25 12:30:15','2026-04-25 17:18:25',3,NULL,NULL),(3,'450-1852','blown',2049,9900,1449,500,1,2,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-26 15:59:09',3,NULL,4),(4,'450-1853','blown',0,0,628,0,NULL,2,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',3,NULL,NULL),(5,'450-1949','blown',2054,1000,1004,9950,-1,2,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-26 08:38:50',3,NULL,4),(6,'450-1950','blown',2095,0,2095,0,NULL,2,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',3,NULL,NULL),(7,'450-1951','blown',2100,0,2100,0,NULL,2,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',3,NULL,NULL),(8,'450-1952','blown',0,0,882,0,NULL,2,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',3,NULL,NULL),(9,'450-1631','blown',0,0,179,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(10,'450-1867','blown',2096,0,2096,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(11,'450-1956','blown',2099,0,2099,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(12,'450-1957','blown',0,0,230,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(13,'450-1958','blown',2100,0,2100,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(14,'450-1959','blown',2100,0,2100,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(15,'450-1960','blown',2100,0,2100,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(16,'450-1961','blown',2095,0,2095,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(17,'450-1962','blown',2095,0,2095,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(18,'450-1964','blown',2095,0,2095,0,NULL,4,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',4,NULL,NULL),(19,'450-1845','blown',0,0,1874,0,NULL,8,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',5,NULL,NULL),(20,'450-1846','blown',0,0,694,0,NULL,8,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',5,NULL,NULL),(21,'450-1848','blown',0,0,1988,0,NULL,8,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',5,NULL,NULL),(22,'450-1897','blown',2054,0,2054,0,NULL,8,2.0,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',5,NULL,NULL),(23,'450-013521','blown',0,0,1194,0,NULL,12,2.8,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',6,NULL,NULL),(24,'450-013641','blown',0,0,1453,0,NULL,12,2.8,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',6,NULL,NULL),(25,'450-020076','blown',0,0,1858,0,NULL,12,2.8,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',6,NULL,NULL),(26,'755-004826','mlt',0,0,1634,0,NULL,12,5.2,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',7,NULL,NULL),(27,'450-019505','blown',0,0,904,0,NULL,12,0.4,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',8,NULL,NULL),(28,'450-020130','blown',2098,0,2098,0,NULL,12,0.4,'in_stock',0,'oprava_rozlomene_metry_KDP','2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',8,NULL,NULL),(29,'450-020434','blown',0,0,691,0,NULL,12,0.4,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',8,NULL,NULL),(30,'450-1468','blown',0,0,922,0,NULL,12,0.4,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',8,NULL,NULL),(31,'450-1469','blown',0,0,1764,0,NULL,12,0.4,'in_stock',0,'oprava_rozlomene_metry_KDP','2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',8,NULL,NULL),(32,'450-1470','blown',2094,8400,694,7000,-1,12,2.0,'in_stock',0,'Pohoda PDF měla chybně sloučené číslo; délka a údaj ze štítku 2023-05-12, foto Drum-1','2026-04-25','2026-04-25 12:30:15','2026-04-26 15:47:15',8,NULL,4),(33,'450-1725','blown',0,0,1321,0,NULL,12,0.4,'in_stock',0,'oprava_rozlomene_metry_KDP','2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',8,NULL,NULL),(34,'450-1731','blown',2099,0,2099,0,NULL,12,0.4,'in_stock',0,'oprava_rozlomene_metry_KDP','2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',8,NULL,NULL),(35,'450-1734','blown',2060,0,2060,0,NULL,12,0.4,'in_stock',0,'oprava_rozlomene_metry_KDP','2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',8,NULL,NULL),(36,'450-1905','blown',2093,0,2093,0,NULL,24,2.8,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',9,NULL,NULL),(37,'450-1965','blown',2090,0,2090,0,NULL,24,2.8,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',9,NULL,NULL),(38,'450-1966','blown',2094,0,2094,0,NULL,24,2.8,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',9,NULL,NULL),(39,'1000-3992','mlt',0,0,826,0,NULL,24,5.2,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',10,NULL,NULL),(40,'755-004031','mlt',0,0,0,0,NULL,24,5.2,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',10,NULL,NULL),(41,'755-004604','mlt',0,0,0,0,NULL,24,5.2,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',10,NULL,NULL),(42,'755-004608','mlt',2099,0,2099,0,NULL,24,5.2,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',10,NULL,NULL),(43,'600-009484','blown',0,0,1835,0,NULL,24,3.2,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',11,NULL,NULL),(44,'600-922','blown',0,0,1795,0,NULL,24,3.2,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',11,NULL,NULL),(45,'1000-012441','mlt',4618,0,4618,0,NULL,48,5.2,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',12,NULL,NULL),(46,'1000-011969','mlt',2180,0,2180,0,NULL,72,5.7,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',13,NULL,NULL),(47,'1000-012089','mlt',2064,0,2064,0,NULL,72,5.7,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',13,NULL,NULL),(48,'750-161024501','mlt',0,0,327,0,NULL,48,3.8,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',14,NULL,NULL),(49,'A464006','mlt',0,0,1323,0,NULL,72,5.2,'in_stock',0,NULL,'2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',15,NULL,NULL),(50,'450-020678','blown',2091,2091,1881,1881,-1,24,2.8,'in_stock',0,'pouze z fotografie nálepky, ručně přepsáno','2026-04-25','2026-04-25 12:30:15','2026-04-25 17:18:25',9,NULL,NULL),(51,'450-1983','blown',2096,4201,1783,3888,-1,8,2.0,'in_stock',0,'foto Drum-3; dřevěný buben + ruč. PS TÁBOR','2026-04-25','2026-04-25 12:30:15','2026-04-25 17:18:25',5,NULL,NULL),(52,'450-020652','blown',2095,0,2095,0,NULL,2,2.0,'in_stock',0,'foto Drum-4','2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',3,NULL,NULL),(53,'450-020656','blown',2094,0,2094,0,NULL,2,2.0,'in_stock',0,'foto Drum-5','2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',3,NULL,NULL),(54,'450-1980','blown',2100,0,2100,0,NULL,8,2.0,'in_stock',0,'foto Drum-8; odlišné DRUM 450-1980 vs 450-1983','2026-04-25','2026-04-25 12:30:15','2026-04-25 12:30:15',5,NULL,NULL),(55,'450-99999','blown',2100,6000,2100,6000,NULL,2,2.0,'in_stock',NULL,NULL,'2026-04-26','2026-04-26 11:19:28','2026-04-26 11:19:28',3,4,4),(56,'450-88888','blown',2100,5000,0,3014,-1,24,2.8,'written_off',NULL,NULL,'2026-04-26','2026-04-26 11:27:28','2026-04-26 16:55:15',9,4,4),(59,'450-66666','blown',2100,5000,2100,5000,NULL,24,2.8,'in_stock',NULL,NULL,'2026-04-26','2026-04-26 15:18:09','2026-04-26 15:18:09',NULL,4,4);
/*!40000 ALTER TABLE `cable_spool` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cable_spool_event`
--

DROP TABLE IF EXISTS `cable_spool_event`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cable_spool_event` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `occurred_at` datetime NOT NULL,
  `type` varchar(32) NOT NULL,
  `visible_m` int(11) DEFAULT NULL,
  `used_meters` int(11) DEFAULT NULL,
  `project_label` varchar(255) DEFAULT NULL,
  `note` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `spool_id` int(11) NOT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `corrects_event_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_38C08B66A1A590BB` (`spool_id`),
  KEY `IDX_38C08B66B03A8386` (`created_by_id`),
  KEY `IDX_38C08B665D4B7A45` (`corrects_event_id`),
  KEY `idx_event_spool_time` (`spool_id`,`occurred_at`),
  CONSTRAINT `FK_38C08B665D4B7A45` FOREIGN KEY (`corrects_event_id`) REFERENCES `cable_spool_event` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_38C08B66A1A590BB` FOREIGN KEY (`spool_id`) REFERENCES `cable_spool` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_38C08B66B03A8386` FOREIGN KEY (`created_by_id`) REFERENCES `app_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cable_spool_event`
--

LOCK TABLES `cable_spool_event` WRITE;
/*!40000 ALTER TABLE `cable_spool_event` DISABLE KEYS */;
INSERT INTO `cable_spool_event` VALUES (1,'2026-04-25 12:00:00','laid_section',3885,324,'ZTV ROUDNE',NULL,'2026-04-25 12:49:39',2,NULL,NULL),(2,'2026-04-25 12:00:00','laid_section',8296,104,'Milevsko',NULL,'2026-04-25 12:49:39',32,NULL,NULL),(3,'2026-04-25 12:01:00','laid_section',8108,188,'Plánský jez',NULL,'2026-04-25 12:49:39',32,NULL,NULL),(4,'2026-04-25 12:02:00','laid_section',8021,87,'LIDICKÁ',NULL,'2026-04-25 12:49:39',32,NULL,NULL),(5,'2026-04-25 12:03:00','laid_section',7843,178,'Kardaška R.',NULL,'2026-04-25 12:49:39',32,NULL,NULL),(6,'2026-04-25 12:00:00','laid_section',1881,210,'ZLIV ZTV POD VODOJE',NULL,'2026-04-25 12:49:39',50,NULL,NULL),(7,'2026-04-25 12:00:00','laid_section',3888,313,'TÁBOR',NULL,'2026-04-25 12:49:39',51,NULL,NULL),(8,'2026-04-26 07:40:10','meter_reading',7600,243,'TEST',NULL,'2026-04-26 07:40:10',32,4,NULL),(9,'2026-04-26 07:45:35','meter_reading',7200,400,'TEST 2',NULL,'2026-04-26 07:45:35',32,4,NULL),(11,'2026-04-26 08:37:11','meter_reading',100,200,'TEST 3',' [přetočení čítače, mod 10000]','2026-04-26 08:37:11',3,4,NULL),(12,'2026-04-26 08:38:50','meter_reading',9950,1050,'TEST 4',' [přetočení čítače, mod 10000]','2026-04-26 08:38:50',5,4,NULL),(13,'2026-04-26 00:00:00','meter_reading',7000,200,'TEST 4',NULL,'2026-04-26 15:47:15',32,4,NULL),(14,'2026-04-26 15:59:09','meter_reading',500,400,'TEST 5',NULL,'2026-04-26 15:59:09',3,4,NULL),(15,'2026-04-26 16:21:20','meter_reading',4500,500,'ZAFUK 1',NULL,'2026-04-26 16:21:20',56,4,NULL),(16,'2026-04-26 16:21:43','meter_reading',4114,386,'ZAFUK 2',NULL,'2026-04-26 16:21:43',56,4,NULL),(17,'2026-04-26 16:22:17','meter_reading',3014,1100,'ZAFUK 3',NULL,'2026-04-26 16:22:17',56,4,NULL),(18,'2026-04-26 16:55:15','writeoff',NULL,114,'Odpis z evidence — likvidace zbytku','zbytek vymotano do zmotku','2026-04-26 16:55:15',56,4,NULL);
/*!40000 ALTER TABLE `cable_spool_event` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cable_type`
--

DROP TABLE IF EXISTS `cable_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cable_type` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `full_description` longtext DEFAULT NULL,
  `family` varchar(32) NOT NULL,
  `fiber_count` int(11) NOT NULL,
  `construction_code` varchar(32) DEFAULT NULL,
  `diameter_mm` decimal(4,1) DEFAULT NULL,
  `is_active` tinyint(4) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `updated_by_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_37A8D7377153098` (`code`),
  KEY `IDX_37A8D73B03A8386` (`created_by_id`),
  KEY `IDX_37A8D73896DBBDE` (`updated_by_id`),
  CONSTRAINT `FK_37A8D73896DBBDE` FOREIGN KEY (`updated_by_id`) REFERENCES `app_user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_37A8D73B03A8386` FOREIGN KEY (`created_by_id`) REFERENCES `app_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cable_type`
--

LOCK TABLES `cable_type` WRITE;
/*!40000 ALTER TABLE `cable_type` DISABLE KEYS */;
INSERT INTO `cable_type` VALUES (3,'KO-02-9-Z444','Kabel optický A-D2Y HD 2E9/125, FRP, Blown Cable BLK, Z444, KDP','Kabel optický A-D2Y HD 2E9/125, FRP, Blown Cable BLK, Z444, KDP','blown',2,'Z444',2.0,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(4,'KO-04-9-Z444','Kabel optický A-D2Y HD 4E9/125, FRP, Blown Cable BLK, Z444, KDP','Kabel optický A-D2Y HD 4E9/125, FRP, Blown Cable BLK, Z444, KDP','blown',4,'Z444',2.0,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(5,'KO-08-9-Z444','Kabel optický A-D2Y HD 8E9/125, FRP 0,4mm, Blown Cable, Starnet BLK, Z444, 2mm,','Kabel optický A-D2Y HD 8E9/125, FRP 0,4mm, Blown Cable, Starnet BLK, Z444, 2mm,','blown',8,'Z444',2.0,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(6,'KO-12-9-Z006','Kabel optický A-D4Y Blown Cable, 12vl., 9/125, PA, 2,8mm, Z006, CLT, KDP','Kabel optický A-D4Y Blown Cable, 12vl., 9/125, PA, 2,8mm, Z006, CLT, KDP','blown',12,'Z006',2.8,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(7,'KO-12-9-Z019','Kabel optický A-DQ(ZN)2Y, 5x1,5, 12vl., 9/125, PE, 5,2mm, Z019, MLT, KDP','Kabel optický A-DQ(ZN)2Y, 5x1,5, 12vl., 9/125, PE, 5,2mm, Z019, MLT, KDP','mlt',12,'Z019',5.2,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(8,'KO-12-9-Z444','Kabel optický A-D2Y HD 12E9/125, FRP 0,4mm, Blown Starnet Cable, BLK, Z444, KDP 904 0','Kabel optický A-D2Y HD 12E9/125, FRP 0,4mm, Blown Starnet Cable, BLK, Z444, KDP 904 0','blown',12,'Z444',0.4,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(9,'KO-24-9-Z006','Kabel optický, Blown Cable, 24vl., 9/125, LFP, 2,8mm, Z006, CLT, KDP','Kabel optický, Blown Cable, 24vl., 9/125, LFP, 2,8mm, Z006, CLT, KDP','blown',24,'Z006',2.8,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(10,'KO-24-9-Z019','Kabel optický A-DQ(ZN)2Y, 5x1,5, 24vl., 9/125, PE, 5,2mm, Z019, MLT, KDP826','Kabel optický A-DQ(ZN)2Y, 5x1,5, 24vl., 9/125, PE, 5,2mm, Z019, MLT, KDP826','mlt',24,'Z019',5.2,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(11,'KO-24-9-Z238','Kabel optický, Blown Cable, 24vl., 9/125, LFP, 3,2mm, Z238, CLT, KDP','Kabel optický, Blown Cable, 24vl., 9/125, LFP, 3,2mm, Z238, CLT, KDP','blown',24,'Z238',3.2,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(12,'KO-48-9-Z019','Kabel optický A-DQ(ZN)2Y, 5x1,5, 48vl., 9/125, PE, 5,2mm, Z019, MLT, KDP','Kabel optický A-DQ(ZN)2Y, 5x1,5, 48vl., 9/125, PE, 5,2mm, Z019, MLT, KDP','mlt',48,'Z019',5.2,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(13,'KO-72-9-TM0I','Kabel optický A-DQ2Y, 6x1,5, 72vl., 9/125, PE, 5,7mm,Starnet TM0I, MLT, KDP 2 180 0','Kabel optický A-DQ2Y, 6x1,5, 72vl., 9/125, PE, 5,7mm,Starnet TM0I, MLT, KDP 2 180 0','mlt',72,'TM0I',5.7,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(14,'WKO-48-9-MLT-38','Kabel optický WIREX A-DQ2Y, 4x1.2, 48vl., 9/125, PE,Starnet 3.8mm, MLT 327 0 327','Kabel optický WIREX A-DQ2Y, 4x1.2, 48vl., 9/125, PE,Starnet 3.8mm, MLT 327 0 327','mlt',48,NULL,3.8,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL),(15,'WKO-72-9-MLT-52','Kabel optický WIREX A-DQ(ZN)2Y, 6x1.4, 72vl., 9/125,Starnet PE, 5.2mm, MLT 1 323 0 1 323','Kabel optický WIREX A-DQ(ZN)2Y, 6x1.4, 72vl., 9/125,Starnet PE, 5.2mm, MLT 1 323 0 1 323','mlt',72,NULL,5.2,1,'2026-04-25 12:30:15','2026-04-25 12:30:15',NULL,NULL);
/*!40000 ALTER TABLE `cable_type` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doctrine_migration_versions`
--

DROP TABLE IF EXISTS `doctrine_migration_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `doctrine_migration_versions` (
  `version` varchar(191) NOT NULL,
  `executed_at` datetime DEFAULT NULL,
  `execution_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doctrine_migration_versions`
--

LOCK TABLES `doctrine_migration_versions` WRITE;
/*!40000 ALTER TABLE `doctrine_migration_versions` DISABLE KEYS */;
INSERT INTO `doctrine_migration_versions` VALUES ('DoctrineMigrations\\Version20260419200000','2026-04-25 12:41:19',42),('DoctrineMigrations\\Version20260425100103','2026-04-25 10:01:09',7),('DoctrineMigrations\\Version20260425100619','2026-04-25 10:06:45',46),('DoctrineMigrations\\Version20260425103037','2026-04-25 10:30:44',211),('DoctrineMigrations\\Version20260426120000','2026-04-25 16:58:56',2),('DoctrineMigrations\\Version20260427200000','2026-04-26 10:26:32',20);
/*!40000 ALTER TABLE `doctrine_migration_versions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'optica_sklad'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-26 19:04:50
