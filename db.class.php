<?php
// db.class.php

// Namespace für unser Projekt
namespace MyProject;

// Einbindung der Konfigurationsdatei
require_once dirname($_SERVER['DOCUMENT_ROOT']) . '/config/sec.config.php';

/**
 * Database Klasse für CRUD-Operationen mit MySQL (PDO).
 * Diese Klasse verwendet das Singleton-Muster, um sicherzustellen, dass nur eine Instanz der Datenbankverbindung existiert.
 */
class Database
{
	// Einzige Instanz der Klasse
	private static $instance = null;
	// Variable für die Datenbankverbindung
	private $db;
	// Geheimer Schlüssel für Verschlüsselung
	private $secretKey;

	/**
	 * Konstruktor der Klasse.
	 * Initialisiert die Datenbankverbindung und den geheimen Schlüssel.
	 */
	private function __construct()
	{
		try {
			$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8';
			$options = [
				\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_EMULATE_PREPARES => false
			];
			$this->db = new \PDO($dsn, DB_USER, DB_PASSWORD, $options);
			$this->secretKey = SECRET_KEY;
		} catch (\PDOException $e) {
			error_log($e->getMessage());
			die('Datenbankverbindung fehlgeschlagen. Weitere Informationen finden Sie im Fehlerprotokoll.');
		}
	}

	/**
	 * Erweiterte Funktion zum Einfügen von Datensätzen in die Datenbank.
	 * 
	 * @param string $table Der Name der Tabelle
	 * @param array $data Ein assoziatives Array der einzufügenden Daten
	 * @param bool $transaction Gibt an, ob die Operation in einer Transaktion ausgeführt werden soll
	 * @return array Ein Array mit der ID des zuletzt eingefügten Datensatzes und der Anzahl der eingefügten Zeilen
	 */
	public function create($table, $data, $transaction = false)
	{
		try {
			if ($transaction) {
				$this->db->beginTransaction();
			}
			$fields = implode(',', array_keys($data));
			$placeholders = ':' . implode(', :', array_keys($data));
			$sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";
			$stmt = $this->db->prepare($sql);
			foreach ($data as $key => $value) {
				$stmt->bindValue(':' . $key, $this->sanitizeInput($value));
			}
			$stmt->execute();
			$lastInsertId = $this->db->lastInsertId();
			if ($transaction) {
				$this->db->commit();
			}
			return ['lastInsertId' => $lastInsertId, 'rowCount' => $stmt->rowCount()];
		} catch (\PDOException $e) {
			if ($transaction) {
				$this->db->rollBack();
			}
			error_log($e->getMessage());
			return ['error' => 'Fehler beim Einfügen des Datensatzes: ' . $e->getMessage()];
		}
	}

	/**
	 * Erweiterte Funktion zum Abrufen von Datensätzen aus der Datenbank.
	 * 
	 * @param string $table Der Name der Tabelle
	 * @param array $options Ein assoziatives Array der Optionen (Felder, Bedingungen, Sortierung, Limit)
	 * @return array Ein Array der abgerufenen Datensätze
	 */
	public function read($table, $options = [])
	{
		try {
			$fields = $options['fields'] ?? '*';
			$sql = "SELECT {$fields} FROM {$table}";
			if (!empty($options['conditions'])) {
				$conditions = $options['conditions'];
				$sql .= ' WHERE ' . implode(' AND ', array_map(fn ($key) => "$key = :$key", array_keys($conditions)));
			}
			if (!empty($options['sort'])) {
				$sql .= ' ORDER BY ' . $options['sort'];
			}
			if (!empty($options['limit'])) {
				$sql .= ' LIMIT ' . $options['limit'];
			}
			$stmt = $this->db->prepare($sql);
			if (!empty($options['conditions'])) {
				foreach ($conditions as $key => $value) {
					$stmt->bindValue(':' . $key, $this->sanitizeInput($value));
				}
			}
			$stmt->execute();
			return $stmt->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\PDOException $e) {
			error_log($e->getMessage());
			return ['error' => 'Fehler beim Abrufen der Datensätze: ' . $e->getMessage()];
		}
	}

	/**
	 * Erweiterte Funktion zum Aktualisieren von Datensätzen in der Datenbank.
	 * 
	 * @param string $table Der Name der Tabelle
	 * @param array $data Ein assoziatives Array der zu aktualisierenden Daten
	 * @param array $conditions Ein assoziatives Array der Bedingungen
	 * @param bool $transaction Gibt an, ob die Operation in einer Transaktion ausgeführt werden soll
	 * @return array Ein Array mit der Anzahl der aktualisierten Zeilen
	 */
	public function update($table, $data, $conditions, $transaction = false)
	{
		try {
			if ($transaction) {
				$this->db->beginTransaction();
			}
			$fields = implode(',', array_map(fn ($key) => "$key = :$key", array_keys($data)));
			$sql = "UPDATE {$table} SET {$fields} WHERE " . implode(' AND ', array_map(fn ($key) => "$key = :$key", array_keys($conditions)));
			$stmt = $this->db->prepare($sql);
			foreach (array_merge(
				$data,
				$conditions
			) as $key => $value) {
				$stmt->bindValue(':' . $key, $this->sanitizeInput($value));
			}
			$stmt->execute();
			if ($transaction) {
				$this->db->commit();
			}
			return ['rowCount' => $stmt->rowCount()];
		} catch (\PDOException $e) {
			if ($transaction) {
				$this->db->rollBack();
			}
			error_log($e->getMessage());
			return ['error' => 'Fehler beim Aktualisieren der Datensätze: ' . $e->getMessage()];
		}
	}

	/**
	 * Erweiterte Funktion zum Löschen von Datensätzen aus der Datenbank.
	 * 
	 * @param string $table Der Name der Tabelle
	 * @param array $conditions Ein assoziatives Array der Bedingungen
	 * @param bool $transaction Gibt an, ob die Operation in einer Transaktion ausgeführt werden soll
	 * @param bool $useOrConditions Gibt an, ob OR-Bedingungen verwendet werden sollen
	 * @return array Ein Array mit der Anzahl der gelöschten Zeilen und den IDs der gelöschten Datensätze
	 */
	public function delete($table, $conditions, $transaction = false, $useOrConditions = false, $suppressLogging = false)
	{
		try {
			if ($transaction) {
				$this->db->beginTransaction();
			}
			$connector = $useOrConditions ? ' OR ' : ' AND ';
			$sql = "DELETE FROM {$table} WHERE " . implode($connector, array_map(fn ($key) => "$key = :$key", array_keys($conditions)));
			$stmt = $this->db->prepare($sql);
			foreach ($conditions as $key => $value) {
				$stmt->bindValue(':' . $key, $this->sanitizeInput($value));
			}
			$stmt->execute();
			if ($transaction) {
				$this->db->commit();
			}
			$rowCount = $stmt->rowCount();
			if ($rowCount === 0) {
				return ['message' => 'Keine Datensätze gefunden zum Löschen.'];
			}
			if (!$suppressLogging) {
				// Logging der Löschaktion
				error_log('Deleted ' . $rowCount . ' rows from ' . $table);
			}
			return ['rowCount' => $rowCount];
		} catch (\PDOException $e) {
			if ($transaction) {
				$this->db->rollBack();
			}
			error_log($e->getMessage());
			return ['error' => 'Fehler beim Löschen der Datensätze: ' . $e->getMessage()];
		}
	}


	/**
	 * Gibt die einzige Instanz der Klasse zurück.
	 * Wenn die Instanz noch nicht existiert, wird sie erstellt.
	 * 
	 * @return Database Die einzige Instanz der Klasse
	 */
	public static function getInstance()
	{
		if (self::$instance === null) {
			self::$instance = new Database();
		}
		return self::$instance;
	}

	/**
	 * Methode zum Testen der Datenbankverbindung.
	 * Die Implementierung erfolgt später.
	 */
	public function testConnection()
	{
		// Code zum Testen der Datenbankverbindung wird später hinzugefügt
	}

	/**
	 * Hilfsfunktion zur Bereinigung von Eingabedaten.
	 * 
	 * @param string $data Die zu bereinigenden Daten
	 * @return string Die bereinigten Daten
	 */
	private function sanitizeInput($data)
	{
		$data = trim($data);
		$data = stripslashes($data);
		$data = htmlspecialchars($data);
		return $data;
	}

	/**
	 * Hilfsfunktion zur Verschlüsselung von Daten.
	 * 
	 * @param string $data Die zu verschlüsselnden Daten
	 * @return string Die verschlüsselten Daten
	 */
	private function encryptData($data)
	{
		// Verschlüsselungslogik wird später hinzugefügt
		return $data;
	}

	// Weitere Funktionen und Methoden werden hier hinzugefügt
}
