<?php

// Return a selected active catalog entry, or create/reuse a catalog row for a new species name.
function resolveSpeciesCatalogId(
    PDO $pdo,
    ?int $speciesId,
    string $englishName,
    ?string $scientificName,
    ?string $localName
): int {
    if ($speciesId !== null) {
        // A submitted catalog ID is valid only while that catalog entry remains active.
        $speciesQuery = $pdo->prepare('SELECT id FROM species WHERE id = :id AND is_active = 1');
        $speciesQuery->execute(['id' => $speciesId]);
        $activeSpeciesId = $speciesQuery->fetchColumn();
        if (!$activeSpeciesId) {
            throw new RuntimeException('The selected species is no longer active. Choose another species or enter it as a new species.');
        }

        return (int) $activeSpeciesId;
    }

    // Reuse names already in the catalog to avoid duplicate entries.
    $existingQuery = $pdo->prepare('SELECT id, is_active FROM species WHERE english_name = :english_name LIMIT 1');
    $existingQuery->execute(['english_name' => $englishName]);
    $existingSpecies = $existingQuery->fetch(PDO::FETCH_ASSOC);
    if ($existingSpecies) {
        if ((int) $existingSpecies['is_active'] !== 1) {
            throw new RuntimeException('A catalog entry with this name is inactive. Ask an administrator to reactivate it.');
        }

        return (int) $existingSpecies['id'];
    }

    try {
        // New entries use the neutral group until an administrator classifies them.
        $insertSpecies = $pdo->prepare(
            'INSERT INTO species (english_name, scientific_name, local_name, species_group)
             VALUES (:english_name, :scientific_name, :local_name, \'Other\')'
        );
        $insertSpecies->execute([
            'english_name' => $englishName,
            'scientific_name' => $scientificName,
            'local_name' => $localName,
        ]);

        return (int) $pdo->lastInsertId();
    } catch (PDOException $exception) {
        // If another request inserted the same unique name concurrently, use its active row.
        if ($exception->getCode() !== '23000') {
            throw $exception;
        }

        $existingQuery->execute(['english_name' => $englishName]);
        $existingSpecies = $existingQuery->fetch(PDO::FETCH_ASSOC);
        if ($existingSpecies && (int) $existingSpecies['is_active'] === 1) {
            return (int) $existingSpecies['id'];
        }

        throw $exception;
    }
}
