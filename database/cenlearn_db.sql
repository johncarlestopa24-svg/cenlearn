-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: cenlearn_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

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
-- Table structure for table `assignment_submissions`
--

DROP TABLE IF EXISTS `assignment_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assignment_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assignment_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `grade` decimal(5,2) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `assign_student` (`assignment_id`,`student_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignment_submissions`
--

LOCK TABLES `assignment_submissions` WRITE;
/*!40000 ALTER TABLE `assignment_submissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `assignment_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignments`
--

DROP TABLE IF EXISTS `assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `teacher_code` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `instructions` text DEFAULT NULL,
  `points` int(11) DEFAULT 100,
  `due_date` datetime DEFAULT NULL,
  `term` varchar(20) NOT NULL DEFAULT 'midterm' COMMENT 'midterm, final, or none',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignments`
--

LOCK TABLES `assignments` WRITE;
/*!40000 ALTER TABLE `assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_attendance_records`
--

DROP TABLE IF EXISTS `class_attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_attendance_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `status` enum('present','late','absent','excused') NOT NULL DEFAULT 'present',
  `remarks` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sess_student` (`session_id`,`student_code`),
  KEY `idx_session` (`session_id`),
  KEY `idx_student` (`student_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_attendance_records`
--

LOCK TABLES `class_attendance_records` WRITE;
/*!40000 ALTER TABLE `class_attendance_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `class_attendance_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_attendance_sessions`
--

DROP TABLE IF EXISTS `class_attendance_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_attendance_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `teacher_code` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL DEFAULT '',
  `attendance_date` date NOT NULL,
  `term` varchar(20) NOT NULL DEFAULT 'midterm',
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_class_date` (`class_id`,`attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_attendance_sessions`
--

LOCK TABLES `class_attendance_sessions` WRITE;
/*!40000 ALTER TABLE `class_attendance_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `class_attendance_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_material_analysis`
--

DROP TABLE IF EXISTS `class_material_analysis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_material_analysis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `topics_json` text DEFAULT NULL,
  `keywords_json` text DEFAULT NULL,
  `definitions_json` text DEFAULT NULL,
  `objectives_json` text DEFAULT NULL,
  `formulas_json` text DEFAULT NULL,
  `dates_json` text DEFAULT NULL,
  `people_json` text DEFAULT NULL,
  `terms_json` text DEFAULT NULL,
  `extracted_text` mediumtext DEFAULT NULL,
  `analyzed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_module_unique` (`class_id`,`module_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_material_analysis`
--

LOCK TABLES `class_material_analysis` WRITE;
/*!40000 ALTER TABLE `class_material_analysis` DISABLE KEYS */;
INSERT INTO `class_material_analysis` VALUES (1,4,1,'SOCA Insight','mod_6a7914b8bb33c.pdf','[\"SOCA Insight\"]','[\"endobj\",\"type\",\"length\",\"fontdescriptor\",\"font\",\"stream\",\"endstream\",\"filter\",\"flatedecode\",\"subtype\"]','[{\"term\":\"Endobj\",\"definition\":\"Key term extracted from material: SOCA Insight\"},{\"term\":\"Type\",\"definition\":\"Key term extracted from material: SOCA Insight\"},{\"term\":\"Length\",\"definition\":\"Key term extracted from material: SOCA Insight\"}]','[\"Master core concepts of SOCA Insight\",\"Understand key vocabulary and definitions in SOCA Insight\"]','[\"FrpA qnUI =0Rd Y=*T G XC 2f98 JdKA =GIe ct\",\"MY 48=HG0 -AgS v-mn SyuN 90zgo VaJL h\",\"IMfY (h+R \\/p+p E5bAi LIu\\/ L(=PS d0D fHEN iU\"]','[\"2000\",\"2046\",\"1948\",\"1999\",\"2026\"]','[\"Guest User\",\"Microsoft Word\",\"Rmfm Oe\"]','[\"endobj\",\"type\",\"length\",\"fontdescriptor\",\"font\",\"stream\",\"endstream\",\"filter\",\"flatedecode\",\"subtype\",\"basefont\",\"description\",\"adobe\",\"encoding\",\"fontname\"]','%PDF-1.7 % 1 0 obj /Type/Catalog/Pages 2 0 R/Lang(en) /StructTreeRoot 33 0 R/MarkInfo /Marked true /Metadata 84 0 R/ViewerPreferences 85 0 R endobj 2 0 obj /Type/Pages/Count 2/Kids 3 0 R 30 0 R endobj 3 0 obj /Type/Page/Parent 2 0 R/Resources /Font /F1 5 0 R/F2 9 0 R/F3 14 0 R/F4 16 0 R/F5 18 0 R/F6 23 0 R/F7 28 0 R /ExtGState /GS7 7 0 R/GS8 8 0 R /ProcSet /PDF/Text/ImageB/ImageC/ImageI /MediaBox 0 0 595.25 842 /Contents 4 0 R/Group /Type/Group/S/Transparency/CS/DeviceRGB /Tabs/S/StructParents 0 endobj 4 0 obj /Filter/FlateDecode/Length 3629 stream x tPl cRS8 h=J9L aH 4 RRtk Xy,W* N.Em 4i ) 5 a xPgIf at(t 4rPn d%7)c :t,LU WO 8; qEd8 X(H: FrpA qnUI =0Rd Y=*T G XC 2f98 JdKA =GIe ct:S (7X InEi JyDe4 rGCN Uh l lDiov 7;mbdd / endstream endobj 5 0 obj /Type/Font/Subtype/TrueType/Name/F1/BaseFont/BCDEEE+Aptos/Encoding/WinAnsiEncoding/FontDescriptor 6 0 R/FirstChar 32/LastChar 32/Widths 70 0 R endobj 6 0 obj /Type/FontDescriptor/FontName/BCDEEE+Aptos/Flags 32/ItalicAngle 0/Ascent 939/Descent -282/CapHeight 939/AvgWidth 561/MaxWidth 1682/FontWeight 400/XHeight 250/StemV 56/FontBBox -500 -282 1182 939 /FontFile2 71 0 R endobj 7 0 obj /Type/ExtGState/BM/Normal/ca 1 endobj 8 0 obj /Type/ExtGState/BM/Normal/CA 1 endobj 9 0 obj /Type/Font/Subtype/Type0/BaseFont/BCDFEE+TimesNewRomanPS-BoldMT/Encoding/Identity-H/DescendantFonts 10 0 R/ToUnicode 72 0 R endobj 10 0 obj 11 0 R endobj 11 0 obj /BaseFont/BCDFEE+TimesNewRomanPS-BoldMT/Subtype/CIDFontType2/Type/Font/CIDToGIDMap/Identity/DW 1000/CIDSystemInfo 12 0 R/FontDescriptor 13 0 R/W 74 0 R endobj 12 0 obj /Ordering(Identity) /Registry(Adobe) /Supplement 0 endobj 13 0 obj /Type/FontDescriptor/FontName/BCDFEE+TimesNewRomanPS-BoldMT/Flags 32/ItalicAngle 0/Ascent 891/Descent -216/CapHeight 677/AvgWidth 427/MaxWidth 2558/FontWeight 700/XHeight 250/Leading 42/StemV 42/FontBBox -558 -216 2000 677 /FontFile2 73 0 R endobj 14 0 obj /Type/Font/Subtype/TrueType/Name/F3/BaseFont/BCDGEE+TimesNewRomanPS-BoldMT/Encoding/WinAnsiEncoding/FontDescriptor 15 0 R/FirstChar 32/LastChar 32/Widths 75 0 R endobj 15 0 obj /Type/FontDescriptor/FontName/BCDGEE+TimesNewRomanPS-BoldMT/Flags 32/ItalicAngle 0/Ascent 891/Descent -216/CapHeight 677/AvgWidth 427/MaxWidth 2558/FontWeight 700/XHeight 250/Leading 42/StemV 42/FontBBox -558 -216 2000 677 /FontFile2 73 0 R endobj 16 0 obj /Type/Font/Subtype/TrueType/Name/F4/BaseFont/BCDHEE+TimesNewRomanPSMT/Encoding/WinAnsiEncoding/FontDescriptor 17 0 R/FirstChar 32/LastChar 32/Widths 79 0 R endobj 17 0 obj /Type/FontDescriptor/FontName/BCDHEE+TimesNewRomanPSMT/Flags 32/ItalicAngle 0/Ascent 891/Descent -216/CapHeight 693/AvgWidth 401/MaxWidth 2614/FontWeight 400/XHeight 250/Leading 42/StemV 40/FontBBox -568 -216 2046 693 /FontFile2 77 0 R endobj 18 0 obj /Type/Font/Subtype/Type0/BaseFont/BCDIEE+TimesNewRomanPSMT/Encoding/Identity-H/DescendantFonts 19 0 R/ToUnicode 76 0 R endobj 19 0 obj 20 0 R endobj 20 0 obj /BaseFont/BCDIEE+TimesNewRomanPSMT/Subtype/CIDFontType2/Type/Font/CIDToGIDMap/Identity/DW 1000/CIDSystemInfo 21 0 R/FontDescriptor 22 0 R/W 78 0 R endobj 21 0 obj /Ordering(Identity) /Registry(Adobe) /Supplement 0 endobj 22 0 obj /Type/FontDescriptor/FontName/BCDIEE+TimesNewRomanPSMT/Flags 32/ItalicAngle 0/Ascent 891/Descent -216/CapHeight 693/AvgWidth 401/MaxWidth 2614/FontWeight 400/XHeight 250/Leading 42/StemV 40/FontBBox -568 -216 2046 693 /FontFile2 77 0 R endobj 23 0 obj /Type/Font/Subtype/Type0/BaseFont/BCDJEE+TimesNewRomanPS-BoldItalicMT/Encoding/Identity-H/DescendantFonts 24 0 R/ToUnicode 80 0 R endobj 24 0 obj 25 0 R endobj 25 0 obj /BaseFont/BCDJEE+TimesNewRomanPS-BoldItalicMT/Subtype/CIDFontType2/Type/Font/CIDToGIDMap/Identity/DW 1000/CIDSystemInfo 26 0 R/FontDescriptor 27 0 R/W 82 0 R endobj 26 0 obj /Ordering(Identity) /Registry(Adobe) /Supplement 0 endobj 27 0 obj /Type/FontDescriptor/FontName/BCDJEE+TimesNewRomanPS-BoldItalicMT/Flags 32/ItalicAngle -16.4/Ascent 891/Descent -216/CapHeight 677/AvgWidth 412/MaxWidth 1948/FontWeight 700/XHeight 250/Leading 42/StemV 41/FontBBox -547 -216 1401 677 /FontFile2 81 0 R endobj 28 0 obj /Type/Font/Subtype/TrueType/Name/F7/BaseFont/BCDKEE+TimesNewRomanPS-BoldItalicMT/Encoding/WinAnsiEncoding/FontDescriptor 29 0 R/FirstChar 32/LastChar 32/Widths 83 0 R endobj 29 0 obj /Type/FontDescriptor/FontName/BCDKEE+TimesNewRomanPS-BoldItalicMT/Flags 32/ItalicAngle -16.4/Ascent 891/Descent -216/CapHeight 677/AvgWidth 412/MaxWidth 1948/FontWeight 700/XHeight 250/Leading 42/StemV 41/FontBBox -547 -216 1401 677 /FontFile2 81 0 R endobj 30 0 obj /Type/Page/Parent 2 0 R/Resources /Font /F1 5 0 R/F7 28 0 R/F6 23 0 R /ExtGState /GS7 7 0 R/GS8 8 0 R /ProcSet /PDF/Text/ImageB/ImageC/ImageI /MediaBox 0 0 595.25 842 /Contents 31 0 R/Group /Type/Group/S/Transparency/CS/DeviceRGB /Tabs/S/StructParents 1 endobj 31 0 obj /Filter/FlateDecode/Length 299 stream x CbGB DtAIUT endstream endobj 32 0 obj /Author(Guest User) /Creator(Microsoft Word) /CreationDate(D:20260715162956+00 ) /ModDate(D:20260715162956+00 endobj 40 0 obj /Type/ObjStm','2026-08-10 08:00:57');
/*!40000 ALTER TABLE `class_material_analysis` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_material_folders`
--

DROP TABLE IF EXISTS `class_material_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_material_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `folder_type` varchar(50) NOT NULL DEFAULT 'student_ppt',
  `description` text DEFAULT NULL,
  `is_shared` tinyint(1) NOT NULL DEFAULT 1,
  `allow_student_view` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`),
  KEY `folder_type` (`folder_type`),
  KEY `is_shared` (`is_shared`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_material_folders`
--

LOCK TABLES `class_material_folders` WRITE;
/*!40000 ALTER TABLE `class_material_folders` DISABLE KEYS */;
INSERT INTO `class_material_folders` VALUES (1,4,'sadasdas','student_ppt','',1,1,'teacher','2026-08-16 22:48:43','2026-08-17 21:32:49');
/*!40000 ALTER TABLE `class_material_folders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_members`
--

DROP TABLE IF EXISTS `class_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `user_code` varchar(50) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_user` (`class_id`,`user_code`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_members`
--

LOCK TABLES `class_members` WRITE;
/*!40000 ALTER TABLE `class_members` DISABLE KEYS */;
INSERT INTO `class_members` VALUES (1,1,'teacher','2026-08-09 23:20:51'),(2,2,'teacher','2026-08-09 23:21:20'),(3,2,'2023119587','2026-08-09 23:21:20'),(4,2,'2023119520','2026-08-09 23:21:20'),(5,2,'2023119490','2026-08-09 23:21:20'),(6,2,'2023119674','2026-08-09 23:21:20'),(7,2,'2023119508','2026-08-09 23:21:20'),(8,3,'teacher','2026-08-09 23:35:36'),(9,4,'teacher','2026-08-09 23:36:31'),(10,4,'2023119587','2026-08-09 23:36:31'),(11,4,'2023119520','2026-08-09 23:36:31'),(12,4,'2023119490','2026-08-09 23:36:31'),(13,4,'2023119674','2026-08-09 23:36:31'),(14,4,'2023119508','2026-08-09 23:36:31'),(15,2,'2023119494','2026-08-09 23:38:52'),(16,2,'2023119518','2026-08-09 23:38:52'),(17,2,'2023119519','2026-08-09 23:38:52'),(18,2,'2023119492','2026-08-09 23:38:52'),(19,2,'2023119521','2026-08-09 23:38:52'),(20,2,'2023119735','2026-08-09 23:38:52'),(21,2,'2023119495','2026-08-09 23:38:52'),(22,2,'2023119491','2026-08-09 23:38:52'),(23,2,'2023119496','2026-08-09 23:38:52'),(24,2,'2023119601','2026-08-09 23:38:52'),(25,2,'2023119497','2026-08-09 23:38:52'),(26,2,'2023119499','2026-08-09 23:38:52'),(27,2,'2023119504','2026-08-09 23:38:52'),(28,2,'2023119506','2026-08-09 23:38:52'),(29,2,'2023119522','2026-08-09 23:38:52'),(30,2,'2023119510','2026-08-09 23:38:52'),(31,2,'2023119529','2026-08-09 23:38:52'),(32,2,'2023119523','2026-08-09 23:38:52'),(33,2,'2023119513','2026-08-09 23:38:52'),(34,2,'2023119831','2026-08-09 23:38:52'),(35,2,'2019113371','2026-08-09 23:38:52'),(36,2,'2023119489','2026-08-09 23:38:52'),(37,2,'2023119829','2026-08-09 23:38:52'),(38,2,'2023119527','2026-08-09 23:38:52'),(39,4,'2023119494','2026-08-09 23:38:52'),(40,4,'2023119518','2026-08-09 23:38:52'),(41,4,'2023119519','2026-08-09 23:38:52'),(42,4,'2023119492','2026-08-09 23:38:52'),(43,4,'2023119521','2026-08-09 23:38:52'),(44,4,'2023119735','2026-08-09 23:38:52'),(45,4,'2023119495','2026-08-09 23:38:52'),(46,4,'2023119491','2026-08-09 23:38:52'),(47,4,'2023119496','2026-08-09 23:38:52'),(48,4,'2023119601','2026-08-09 23:38:52'),(49,4,'2023119497','2026-08-09 23:38:52'),(50,4,'2023119499','2026-08-09 23:38:52'),(51,4,'2023119504','2026-08-09 23:38:52'),(52,4,'2023119506','2026-08-09 23:38:52'),(53,4,'2023119522','2026-08-09 23:38:52'),(54,4,'2023119510','2026-08-09 23:38:52'),(55,4,'2023119529','2026-08-09 23:38:52'),(56,4,'2023119523','2026-08-09 23:38:52'),(57,4,'2023119513','2026-08-09 23:38:52'),(58,4,'2023119831','2026-08-09 23:38:52'),(59,4,'2019113371','2026-08-09 23:38:52'),(60,4,'2023119489','2026-08-09 23:38:52'),(61,4,'2023119829','2026-08-09 23:38:52'),(62,4,'2023119527','2026-08-09 23:38:52');
/*!40000 ALTER TABLE `class_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_module_links`
--

DROP TABLE IF EXISTS `class_module_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_module_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `repo_id` int(11) NOT NULL,
  `linked_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_repo` (`class_id`,`repo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_module_links`
--

LOCK TABLES `class_module_links` WRITE;
/*!40000 ALTER TABLE `class_module_links` DISABLE KEYS */;
/*!40000 ALTER TABLE `class_module_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_modules`
--

DROP TABLE IF EXISTS `class_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_modules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `uploaded_by` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `topic` varchar(200) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `term` varchar(20) NOT NULL DEFAULT 'midterm',
  PRIMARY KEY (`id`),
  KEY `idx_class_id` (`class_id`),
  KEY `idx_topic` (`topic`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_modules`
--

LOCK TABLES `class_modules` WRITE;
/*!40000 ALTER TABLE `class_modules` DISABLE KEYS */;
INSERT INTO `class_modules` VALUES (1,4,NULL,'teacher','SOCA Insight','mod_6a7914b8bb33c.pdf','SOCA Insight.pdf','',135185,'2026-08-10 08:00:56','midterm');
/*!40000 ALTER TABLE `class_modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_record_columns`
--

DROP TABLE IF EXISTS `class_record_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_record_columns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `term` varchar(20) NOT NULL DEFAULT 'midterm' COMMENT 'midterm or final',
  `component` varchar(50) NOT NULL DEFAULT 'performance',
  `title` varchar(100) NOT NULL,
  `max_score` decimal(6,2) NOT NULL DEFAULT 100.00,
  `sort_order` int(11) DEFAULT 0,
  `session_id` int(11) DEFAULT NULL COMMENT 'linked live session for attendance',
  `quiz_id` int(11) DEFAULT NULL COMMENT 'linked quiz for auto-sync',
  `assignment_id` int(11) DEFAULT NULL COMMENT 'linked assignment for auto-sync',
  `is_f2f` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'face-to-face attendance column',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_record_columns`
--

LOCK TABLES `class_record_columns` WRITE;
/*!40000 ALTER TABLE `class_record_columns` DISABLE KEYS */;
INSERT INTO `class_record_columns` VALUES (1,2,'midterm','written','quiz1',40.00,0,NULL,1,NULL,0,'2026-08-09 23:55:52'),(2,4,'midterm','written','Aug 13',2.00,0,1,NULL,NULL,0,'2026-08-13 22:27:56'),(3,4,'midterm','written','Aug 13',2.00,0,2,NULL,NULL,0,'2026-08-16 12:55:31'),(4,4,'midterm','written','Aug 17',2.00,0,3,NULL,NULL,0,'2026-08-17 21:13:14');
/*!40000 ALTER TABLE `class_record_columns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_record_scores`
--

DROP TABLE IF EXISTS `class_record_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_record_scores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `column_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `score` decimal(6,2) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `col_student` (`column_id`,`student_code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_record_scores`
--

LOCK TABLES `class_record_scores` WRITE;
/*!40000 ALTER TABLE `class_record_scores` DISABLE KEYS */;
INSERT INTO `class_record_scores` VALUES (1,1,2,'2023119490',7.00,'2026-08-10 00:04:06'),(2,2,4,'2023119490',1.00,'2026-08-17 21:31:54');
/*!40000 ALTER TABLE `class_record_scores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_record_weights`
--

DROP TABLE IF EXISTS `class_record_weights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_record_weights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `written_pct` int(11) NOT NULL DEFAULT 20,
  `performance_pct` int(11) NOT NULL DEFAULT 40,
  `exam_pct` int(11) NOT NULL DEFAULT 30,
  `attendance_pct` int(11) NOT NULL DEFAULT 10,
  `extra_weights` text DEFAULT NULL COMMENT 'JSON array of {label, pct} extra weight categories',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `grading_method` varchar(20) NOT NULL DEFAULT 'sum_of_points',
  `base_grade` int(11) NOT NULL DEFAULT 0,
  `midterm_weight` int(11) NOT NULL DEFAULT 40,
  `final_weight` int(11) NOT NULL DEFAULT 60,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_id` (`class_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_record_weights`
--

LOCK TABLES `class_record_weights` WRITE;
/*!40000 ALTER TABLE `class_record_weights` DISABLE KEYS */;
INSERT INTO `class_record_weights` VALUES (1,4,20,40,30,10,'[]','2026-08-16 21:32:52','sum_of_points',50,40,60);
/*!40000 ALTER TABLE `class_record_weights` ENABLE KEYS */;
UNLOCK TABLES;


/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_topic_difficulty` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `avg_score_pct` decimal(5,2) DEFAULT 0.00,
  `total_attempts` int(11) DEFAULT 0,
  `last_updated` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_class_topic` (`class_id`,`topic`),
  KEY `idx_class_id` (`class_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_topic_difficulty`
--

LOCK TABLES `class_topic_difficulty` WRITE;
/*!40000 ALTER TABLE `class_topic_difficulty` DISABLE KEYS */;
INSERT INTO `class_topic_difficulty` VALUES (1,2,'General',17.50,1,'2026-08-10 00:04:06');
/*!40000 ALTER TABLE `class_topic_difficulty` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classes`
--

DROP TABLE IF EXISTS `classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_code` varchar(10) NOT NULL,
  `class_name` varchar(100) NOT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `year_level` int(2) DEFAULT NULL,
  `program_code` varchar(20) DEFAULT NULL,
  `schedule_json` text DEFAULT NULL,
  `schedule_room` varchar(50) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `teacher_code` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_subject_only` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_code` (`class_code`),
  KEY `idx_teacher_code` (`teacher_code`),
  KEY `idx_classes_archived` (`is_archived`),
  KEY `idx_program_code` (`program_code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classes`
--

LOCK TABLES `classes` WRITE;
/*!40000 ALTER TABLE `classes` DISABLE KEYS */;
INSERT INTO `classes` VALUES (1,'CAP02','CAPSTONE PROJECT 01','CAPSTONE PROJECT 01',NULL,NULL,'IS',NULL,NULL,NULL,0,NULL,'teacher','2026-08-09 23:20:51',1),(2,'CAP02-A','CAPSTONE PROJECT 01','','A',4,'IS','[]','',NULL,0,NULL,'teacher','2026-08-09 23:21:20',0),(3,'CAP09','CAPSTONE PROJECT 02','CAPSTONE PROJECT 02',NULL,NULL,'IS',NULL,NULL,NULL,0,NULL,'teacher','2026-08-09 23:35:36',1),(4,'CAP09-A','CAPSTONE PROJECT 02','','A',4,'IS','[]','',NULL,0,NULL,'teacher','2026-08-09 23:36:31',0);
/*!40000 ALTER TABLE `classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `live_admission`
--

DROP TABLE IF EXISTS `live_admission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `live_admission` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `status` enum('waiting','admitted','denied') NOT NULL DEFAULT 'waiting',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `admitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_student` (`session_id`,`student_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `live_admission`
--

LOCK TABLES `live_admission` WRITE;
/*!40000 ALTER TABLE `live_admission` DISABLE KEYS */;
/*!40000 ALTER TABLE `live_admission` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `live_attendance`
--

DROP TABLE IF EXISTS `live_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `live_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `left_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_student_att` (`session_id`,`student_code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `live_attendance`
--

LOCK TABLES `live_attendance` WRITE;
/*!40000 ALTER TABLE `live_attendance` DISABLE KEYS */;
INSERT INTO `live_attendance` VALUES (1,1,'2023119490','2026-08-13 22:53:24','2026-08-13 22:55:03');
/*!40000 ALTER TABLE `live_attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `live_peers`
--

DROP TABLE IF EXISTS `live_peers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `live_peers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `user_code` varchar(50) NOT NULL,
  `peer_id` varchar(120) NOT NULL,
  `name` varchar(120) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'STUDENT',
  `registered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_user` (`session_id`,`user_code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `live_peers`
--

LOCK TABLES `live_peers` WRITE;
/*!40000 ALTER TABLE `live_peers` DISABLE KEYS */;
INSERT INTO `live_peers` VALUES (1,1,'teacher','cenlearn_27d80ed82d508e1373eebe9d778c6b39','','TEACHER','2026-08-13 08:31:55','2026-08-13 22:54:34'),(2,1,'2023119490','ff749cb9-0f46-42c2-baf1-f380c9804370','JOHN CARL DARA-UG','STUDENT','2026-08-13 22:53:24','2026-08-17 21:32:50'),(3,2,'teacher','cenlearn_27d80ed82d508e1373eebe9d778c6b39','','TEACHER','2026-08-13 22:55:38','2026-08-17 21:32:50'),(4,3,'teacher','cenlearn_27d80ed82d508e1373eebe9d778c6b39','','TEACHER','2026-08-17 00:08:04','2026-08-17 21:32:50');
/*!40000 ALTER TABLE `live_peers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `live_sessions`
--

DROP TABLE IF EXISTS `live_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `live_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `teacher_code` varchar(50) NOT NULL,
  `room_id` varchar(100) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `status` enum('scheduled','live','ended') NOT NULL DEFAULT 'scheduled',
  `term` varchar(20) NOT NULL DEFAULT 'midterm' COMMENT 'midterm or final',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `live_sessions`
--

LOCK TABLES `live_sessions` WRITE;
/*!40000 ALTER TABLE `live_sessions` DISABLE KEYS */;
INSERT INTO `live_sessions` VALUES (1,4,'teacher','cenlearn_27d80ed82d508e1373eebe9d778c6b39','Live Class: Aug 13, 08:31 AM','2026-08-13 08:31:00','2026-08-13 08:31:39','2026-08-13 22:55:03','ended','midterm','2026-08-13 08:31:31'),(2,4,'teacher','cenlearn_27d80ed82d508e1373eebe9d778c6b39','Live Class: Aug 13, 10:55 PM','2026-08-13 22:55:00','2026-08-13 22:55:34','2026-08-13 23:16:03','ended','midterm','2026-08-13 22:55:27'),(3,4,'teacher','cenlearn_27d80ed82d508e1373eebe9d778c6b39','Live Class: Aug 17, 12:07 AM','2026-08-17 00:07:00','2026-08-17 00:07:56','2026-08-17 21:13:09','ended','midterm','2026-08-17 00:07:50');
/*!40000 ALTER TABLE `live_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `material_repository`
--

DROP TABLE IF EXISTS `material_repository`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `material_repository` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_code` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT 0,
  `file_hash` varchar(64) NOT NULL,
  `topic` varchar(200) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_hash` (`teacher_code`,`file_hash`),
  KEY `teacher_code` (`teacher_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `material_repository`
--

LOCK TABLES `material_repository` WRITE;
/*!40000 ALTER TABLE `material_repository` DISABLE KEYS */;
/*!40000 ALTER TABLE `material_repository` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `published_grades`
--

DROP TABLE IF EXISTS `published_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `published_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `term` varchar(20) NOT NULL COMMENT 'midterm or final',
  `grade` decimal(6,2) DEFAULT NULL,
  `transmuted` varchar(10) DEFAULT NULL,
  `remarks` varchar(20) DEFAULT NULL COMMENT 'Passed or Failed',
  `published_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_student_term` (`class_id`,`student_code`,`term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `published_grades`
--

LOCK TABLES `published_grades` WRITE;
/*!40000 ALTER TABLE `published_grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `published_grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_attempts`
--

DROP TABLE IF EXISTS `quiz_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quiz_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_heartbeat` datetime NOT NULL DEFAULT current_timestamp(),
  `total_paused_seconds` int(11) DEFAULT 0,
  `tab_switches` int(11) DEFAULT 0,
  `fullscreen_exits` int(11) DEFAULT 0,
  `answers` text DEFAULT NULL,
  `status` enum('in_progress','submitted','terminated') NOT NULL DEFAULT 'in_progress',
  PRIMARY KEY (`id`),
  KEY `idx_quiz_student` (`quiz_id`,`student_code`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_attempts`
--

LOCK TABLES `quiz_attempts` WRITE;
/*!40000 ALTER TABLE `quiz_attempts` DISABLE KEYS */;
INSERT INTO `quiz_attempts` VALUES (1,1,'2023119490','2026-08-10 00:01:43','2026-08-10 00:04:03',0,1,0,'{\"1\":\"Heart\",\"2\":\"H\\u2082O\",\"3\":\"true\",\"4\":\"true\",\"5\":\"true\",\"6\":\"true\",\"7\":\"elephant\",\"8\":\"sunflower\",\"10\":\"yellow, blue, red\"}','submitted');
/*!40000 ALTER TABLE `quiz_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_questions`
--

DROP TABLE IF EXISTS `quiz_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quiz_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `question_text` text NOT NULL,
  `question_type` varchar(30) NOT NULL DEFAULT 'multiple_choice' COMMENT 'multiple_choice, true_false, identification, enumeration, essay',
  `options` text DEFAULT NULL COMMENT 'JSON array',
  `correct_answer` text DEFAULT NULL,
  `points` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `quiz_id` (`quiz_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_questions`
--

LOCK TABLES `quiz_questions` WRITE;
/*!40000 ALTER TABLE `quiz_questions` DISABLE KEYS */;
INSERT INTO `quiz_questions` VALUES (1,1,'General','Which organ pumps blood throughout the human body?','multiple_choice','[\"Brain\",\"Lungs\",\"Heart\",\"Liver\"]','C. Heart',2),(2,1,'General','What is the chemical symbol for water?','multiple_choice','[\"CO\\u2082\",\"H\\u2082O\",\"O\\u2082\",\"NaCl\"]','B. H₂O',2),(3,1,'General','The Earth revolves around the Sun.','true_false','[]','True',2),(4,1,'General','Fish can live without water.','true_false','[]','False',2),(5,1,'General','The capital of Japan is Beijing.','true_false','[]','Tokyo',2),(6,1,'General','Plants make their own food through photosynthesis.','true_false','[]','True',2),(7,1,'General','It is the largest land animal on Earth.','identification','[]','Elephant',2),(8,1,'General','It is the process by which plants make food using sunlight.','identification','[]','Photosynthesis',2),(9,1,'General','Name the three states of matter.','enumeration','[]','Solid, Liquid, Gas',2),(10,1,'General','Give two primary colors.','enumeration','[]','Red, Blue (also Yellow)',2),(11,1,'General','Why is water important to living things? (2–3 sentences)','essay','[]','Teacher Grading / Rubric',10),(12,1,'General','Explain why plants are important to humans. (2–3 sentences)','essay','[]','Teacher Grading / Rubric',10);
/*!40000 ALTER TABLE `quiz_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quiz_submissions`
--

DROP TABLE IF EXISTS `quiz_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quiz_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `answers` text DEFAULT NULL COMMENT 'JSON',
  `score` decimal(5,2) DEFAULT NULL,
  `total_points` int(11) DEFAULT NULL,
  `tab_switches` int(11) DEFAULT 0,
  `fullscreen_exits` int(11) DEFAULT 0,
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quiz_student` (`quiz_id`,`student_code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quiz_submissions`
--

LOCK TABLES `quiz_submissions` WRITE;
/*!40000 ALTER TABLE `quiz_submissions` DISABLE KEYS */;
INSERT INTO `quiz_submissions` VALUES (1,1,'2023119490','{\"1\":\"Heart\",\"2\":\"H\\u2082O\",\"3\":\"true\",\"4\":\"true\",\"5\":\"true\",\"6\":\"true\",\"7\":\"elephant\",\"8\":\"sunflower\",\"10\":\"yellow, blue, red\"}',7.00,40,1,0,'2026-08-10 00:04:06');
/*!40000 ALTER TABLE `quiz_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quizzes`
--

DROP TABLE IF EXISTS `quizzes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `teacher_code` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `instructions` text DEFAULT NULL,
  `time_limit` int(11) DEFAULT NULL COMMENT 'minutes, NULL = no limit',
  `due_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `shuffle_questions` tinyint(1) DEFAULT 0,
  `shuffle_answers` tinyint(1) DEFAULT 0,
  `term` varchar(20) NOT NULL DEFAULT 'midterm' COMMENT 'midterm, final, or none',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `start_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quizzes`
--

LOCK TABLES `quizzes` WRITE;
/*!40000 ALTER TABLE `quizzes` DISABLE KEYS */;
INSERT INTO `quizzes` VALUES (1,2,'teacher','quiz1','',60,NULL,1,1,1,'midterm','2026-08-09 23:55:52',NULL);
/*!40000 ALTER TABLE `quizzes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_weak_topics`
--

DROP TABLE IF EXISTS `student_weak_topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_weak_topics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `weakness_score` decimal(5,2) DEFAULT 0.00,
  `confidence` decimal(5,2) DEFAULT 0.00,
  `detected_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_weak_topic` (`class_id`,`student_code`,`topic`),
  KEY `idx_class_id` (`class_id`),
  KEY `idx_student_code` (`student_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_weak_topics`
--

LOCK TABLES `student_weak_topics` WRITE;
/*!40000 ALTER TABLE `student_weak_topics` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_weak_topics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subject_logbook`
--

DROP TABLE IF EXISTS `subject_logbook`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subject_logbook` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `teacher_code` varchar(50) NOT NULL,
  `log_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `topic_covered` varchar(255) NOT NULL,
  `activities` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_logbook_class` (`class_id`),
  KEY `idx_logbook_teacher` (`teacher_code`),
  CONSTRAINT `fk_logbook_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subject_logbook`
--

LOCK TABLES `subject_logbook` WRITE;
/*!40000 ALTER TABLE `subject_logbook` DISABLE KEYS */;
/*!40000 ALTER TABLE `subject_logbook` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `summative_questions`
--

DROP TABLE IF EXISTS `summative_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `summative_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `test_id` int(11) NOT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `question_type` varchar(50) NOT NULL COMMENT 'multiple_choice, true_false, identification, enumeration, essay',
  `question_text` text NOT NULL,
  `options` text DEFAULT NULL COMMENT 'JSON array',
  `correct_answer` text DEFAULT NULL COMMENT 'Correct answer or JSON array for enumeration',
  `points` int(11) DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `test_id` (`test_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `summative_questions`
--

LOCK TABLES `summative_questions` WRITE;
/*!40000 ALTER TABLE `summative_questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `summative_questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `summative_submissions`
--

DROP TABLE IF EXISTS `summative_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `summative_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `test_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `answers` text DEFAULT NULL COMMENT 'JSON object with question_id => answer',
  `score` decimal(5,2) DEFAULT NULL,
  `total_points` int(11) DEFAULT NULL,
  `time_started` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `test_id` (`test_id`),
  KEY `student_code` (`student_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `summative_submissions`
--

LOCK TABLES `summative_submissions` WRITE;
/*!40000 ALTER TABLE `summative_submissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `summative_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `summative_tests`
--

DROP TABLE IF EXISTS `summative_tests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `summative_tests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `shuffle_questions` tinyint(1) DEFAULT 0,
  `time_limit` int(11) DEFAULT NULL COMMENT 'minutes',
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `summative_tests`
--

LOCK TABLES `summative_tests` WRITE;
/*!40000 ALTER TABLE `summative_tests` DISABLE KEYS */;
/*!40000 ALTER TABLE `summative_tests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_meta`
--

DROP TABLE IF EXISTS `system_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_meta` (
  `meta_key` varchar(50) NOT NULL,
  `meta_value` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_meta`
--

LOCK TABLES `system_meta` WRITE;
/*!40000 ALTER TABLE `system_meta` DISABLE KEYS */;
INSERT INTO `system_meta` VALUES ('schema_version','12','2026-08-17 21:03:29');
/*!40000 ALTER TABLE `system_meta` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teacher_violations`
--

DROP TABLE IF EXISTS `teacher_violations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teacher_violations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_code` varchar(50) NOT NULL,
  `class_id` int(11) NOT NULL,
  `violation_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `related_topic` varchar(200) DEFAULT NULL,
  `related_quiz_id` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_code` (`teacher_code`),
  KEY `class_id` (`class_id`),
  KEY `violation_type` (`violation_type`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teacher_violations`
--

LOCK TABLES `teacher_violations` WRITE;
/*!40000 ALTER TABLE `teacher_violations` DISABLE KEYS */;
INSERT INTO `teacher_violations` VALUES (1,'teacher',2,'missing_material','Quiz \'quiz1\' contains topic \'General\' but no module with this topic exists in the class.','General',1,NULL,NULL,'2026-08-09 23:55:52');
/*!40000 ALTER TABLE `teacher_violations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `topic_performance`
--

DROP TABLE IF EXISTS `topic_performance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `topic_performance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `total_points_earned` decimal(7,2) DEFAULT 0.00,
  `total_points_available` decimal(7,2) DEFAULT 0.00,
  `attempts` int(11) DEFAULT 0,
  `last_attempt` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_topic` (`class_id`,`student_code`,`topic`),
  KEY `idx_class_id` (`class_id`),
  KEY `idx_student_code` (`student_code`),
  KEY `idx_topic` (`topic`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `topic_performance`
--

LOCK TABLES `topic_performance` WRITE;
/*!40000 ALTER TABLE `topic_performance` DISABLE KEYS */;
INSERT INTO `topic_performance` VALUES (1,2,'2023119490','General',7.00,40.00,1,'2026-08-10 00:04:06','2026-08-10 00:04:06','2026-08-17 21:32:50');
/*!40000 ALTER TABLE `topic_performance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_code` varchar(50) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `email_address` varchar(100) DEFAULT NULL,
  `cp_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `year_level` int(2) DEFAULT NULL,
  `section` varchar(10) DEFAULT NULL,
  `program_code` varchar(20) DEFAULT NULL,
  `program_description` varchar(200) DEFAULT NULL,
  `department` varchar(20) DEFAULT NULL,
  `rfid` varchar(50) DEFAULT NULL,
  `user_group` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `graduated_at` datetime DEFAULT NULL,
  `user_status` varchar(20) DEFAULT NULL,
  `session_token` varchar(64) DEFAULT NULL,
  `api_cached_at` datetime DEFAULT NULL,
  `admin_override` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_code` (`user_code`),
  KEY `idx_department` (`department`),
  KEY `idx_user_group` (`user_group`),
  KEY `idx_program_code` (`program_code`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'TEMP-TEACHER-001','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','TEMP',NULL,'TEACHER',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'TEACHER',1,NULL,NULL,NULL,NULL,NULL,0),(2,'TEACHER001','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','John',NULL,'Doe',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'TEACHER',1,NULL,NULL,NULL,NULL,NULL,0),(3,'STUDENT001','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Jane',NULL,'Smith','jane.smith@student.edu',NULL,NULL,NULL,1,'A','BSIT','Bachelor of Science in Information Technology',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(4,'SUPERADMIN','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Super',NULL,'Admin',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'SUPERADMIN',1,NULL,NULL,NULL,NULL,NULL,0),(5,'2023119494','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','JOHN PAUL','ESTO','ABLAZA','JAYPEEABLAZA29@GMAIL.COM','09318252916',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(6,'2023119518','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','CRIS RAINIER','VILLARES','ALAMEDA','CRISALALAMEDA09@GMAIL.COM','09562109987',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(7,'2023119519','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ANDREW','VILLANUEVA','ALOJADO','DELUX385@GMAIL.COM','09669852851',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(8,'2023119587','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','CHRISTIAN JUDE','GEOCADIN','AUNZO',NULL,'09773670953',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(9,'2023119492','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','RON ALLEN','BENITEZ','BACUNA','RONALLENBENITEZ@GMAIL.COM','09163064793',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(10,'2023119520','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','HERNANI III','JALANDONI','BANEZ',NULL,'09952615253',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(11,'2023119521','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','JAYKIRT','MEDIANA','BETITA','KIRT344@GMAIL.COM','09213533852',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(12,'2023119735','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ELAIZA ANNE','AUNZO','CEZAR','CYPHERHYPER903@GMAIL.COM','09163931008',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(13,'2023119490','$2y$10$oNwXWym71oukBt7E.U/rn.2xMQe02j9sUpte7cKIztWpoNnhcfCDi','JOHN CARL','MAGLIQUIANG','DARA-UG','','09633876065','HDA ALEGRIA, BRGY. LAG-ASAN, BAGO CITY','MALE',4,'A','IS','BACHELOR OF SCIENCE IN INFORMATION SYSTEMS',NULL,'2771724414','STUDENT',1,'2026-08-16 22:59:28',NULL,NULL,NULL,'2026-08-16 22:59:28',0),(14,'2023119495','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','MICO JOHN','DE LA TORRE','DELIMA','DELIMAMICOJOHN@GMAIL.COM','09707425713',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(15,'2023119674','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','JOHN CARLO','MORALES','FAMULAGA',NULL,'09165080630',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(16,'2023119491','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','REX ANTHONY','SEGOVIA','GASTADOR','REXANTHONYGASTADOR@GMAIL.COM','09309758934',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(17,'2023119496','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','JOY','ESCONDE','GONZAGA','JOYESCONDEGONZAGA@GMAIL.COM','09319121526',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(18,'2023119601','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','RONALD','TAGAPULOT','GONZALES','MLROLANDGONZALES@GMAIL.COM','09673206667',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(19,'2023119497','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','CRISTEL MAYEN','CANDELARIO','GUMATA','CRISTELMAYENGUMATA14@GMAIL.COM','09663965659',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(20,'2023119499','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','HEINZ DWIN','MACAHILIG','HERBOLARIO','HEINZHERBOLARIO101@GMAIL.COM','09918721022',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(21,'2023119504','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ELMAR','TRAJECO','INION','INIONELMAR58@GMAIL.COM','09858780149',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(22,'2023119506','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','JULYN','LEPANGUE','JAROBEL','JAROBELJULYN@GMAIL.COM','09814070897',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(23,'2023119522','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','MA. PAULA','CARITATIVO','LAURENO','MAPAULALAURENO@GMAIL.COM','09614354701',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(24,'2023119510','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','MARK SID','JACILDO','LUCERO','CARLUCERO911@GMAIL.COM','09995557360',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(25,'2023119508','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','KATE MARSHIA','JAVIERO','MELOS','KATEMARSHIAMELOS@GMAIL.COM','09384987568',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(26,'2023119529','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','SOFIA NICOLE','SITCHON','NATAN','NATANSOFIANICOLE85@GMAIL.COM','09270658547',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(27,'2023119523','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ALYAH GRACE','LIBA','ODTOHAN','ALYAHGRACEODTOHAN@GMAIL.COM','09991929038',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(28,'2023119513','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','VINCENT','CAJULAO','SAEN','SAENVINCENT@GMAIL.COM','09656831075',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(29,'2023119831','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','JOEVAL','AMALLO','SALIBIO','JOEVAL.SALIBIO@GMAIL.COM','09773108436',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(30,'2019113371','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','EARL ADRIAN','MAULAS','SARCIA','SIREHATE123@GMAIL.COM','09195774591',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(31,'2023119489','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','JANICA JADE','MARATA','SUMAGAYSAY','SUMAGAYSAYJANICA@GMAIL.COM','09637572709',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(32,'2023119829','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','CHRISTIAN','BABOR','VELOSO','CHRISTIANVELOSO1471@GMAIL.COM','09319092424',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(33,'2023119527','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ANGEL','BRILLO','ZAMORA','ANGELBRILL04@GMAIL.COM','09261232481',NULL,NULL,3,'A','IS','Bachelor of Science in Information Systems',NULL,NULL,'STUDENT',1,NULL,NULL,NULL,NULL,NULL,0),(34,'teacher','$2y$10$VcGfdFH.gJ2ZuBiAhToxPOahtHc/udIhgVxeWr7YEZCdc74/47F9C',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'TEACHER',1,'2026-08-18 00:49:28',NULL,NULL,'5de60cec7ebd60362e738144c2a64edfb40575719850bc0f7151de2ee960bca7','2026-08-09 17:19:48',0);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'cenlearn_db'
--
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `sp_resequence_all_ids` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE PROCEDURE `sp_resequence_all_ids`()
BEGIN
    -- WARNING: Resequencing parent primary keys without updating foreign key references
    -- corrupts relational integrity. Resequencing is disabled by default to protect database relationships.
    SELECT 'Resequence aborted: Primary keys cannot be safely renumbered without cascading foreign keys.' AS `status`;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-18  1:17:59
