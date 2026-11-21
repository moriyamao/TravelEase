-- FR-22/23. Amounts use the app's two-decimal convention.
-- Payer and traveler identities are snapshots retained with the trip.
CREATE TABLE trip_expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    category ENUM('sightseeing','food','transport','lodging','activity','shopping','other') NOT NULL,
    currency CHAR(3) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL CHECK (amount_minor BETWEEN 1 AND 9999999999),
    payer_id INT UNSIGNED NOT NULL,
    payer_name VARCHAR(100) NOT NULL,
    split_mode ENUM('equal','custom') NOT NULL,
    receipt_url VARCHAR(2048) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trip_expenses_trip (trip_id, id),
    CONSTRAINT fk_trip_expenses_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trip_expense_shares (
    expense_id INT UNSIGNED NOT NULL,
    traveler_id INT UNSIGNED NOT NULL,
    traveler_name VARCHAR(100) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL CHECK (amount_minor BETWEEN 1 AND 9999999999),
    settled_at DATETIME NULL,
    PRIMARY KEY (expense_id, traveler_id),
    CONSTRAINT fk_expense_shares_expense FOREIGN KEY (expense_id) REFERENCES trip_expenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
