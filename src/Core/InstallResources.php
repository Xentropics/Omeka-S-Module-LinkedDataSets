<?php

declare(strict_types=1);

/*
 * Copyright Daniel Berthereau, 2018-2023
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace LinkedDataSets\Core;

require_once __DIR__ . '/CustomVocabMergeHelper.php';
require_once __DIR__ . '/ResourceTemplateMergeHelper.php';

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Exception\RuntimeException;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use Omeka\Entity\Vocabulary;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;
use LinkedDataSets\Core\ResourceTemplateMergeHelper;
use LinkedDataSets\Core\CustomVocabMergeHelper;

class InstallResources
{
    /**
     * @var \Laminas\ServiceManager\ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Api
     */
    protected $api;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        // The api plugin allows to search one resource without throwing error.
        $this->api = $services->get('ControllerPluginManager')->get('api');
    }

    /**
     * Allows to manage all resources methods that should run once only and that
     * are generic to all modules. A little config over code.
     *
     * @return self
     */
    public function __invoke()
    {
        return $this;
    }

    /**
     * Check all resources that are in the path data/ of a module.
     *
     * @throws \Omeka\Module\Exception\ModuleCannotInstallException
     */
    public function checkAllResources(string $module): bool
    {
        $filepathData = OMEKA_PATH . '/modules/' . $module . '/data/';

        // Vocabularies.
        foreach ($this->listFilesInDir($filepathData . 'vocabularies', ['json']) as $filepath) {
            $data = file_get_contents($filepath);
            $data = json_decode($data, true);
            if (!is_array($data)) {
                throw new ModuleCannotInstallException((string) new Message(
                    'An error occured when loading vocabulary "%s": file has no json content.', // @translate
                    pathinfo($filepath, PATHINFO_FILENAME)
                ));
            }
            $exists = $this->checkVocabulary($data, $module);
            if (is_null($exists)) {
                throw new ModuleCannotInstallException((string) new Message(
                    'An error occured when adding the prefix "%s": another vocabulary exists. Resolve the conflict before installing this module.', // @translate
                    $data['vocabulary']['o:prefix']
                ));
            }
        }

        // Custom vocabs.
        // The presence of the module should be already checked during install.
        foreach ($this->listFilesInDir($filepathData . 'custom-vocabs') as $filepath) {
            $exists = $this->checkCustomVocab($filepath);
            if (is_null($exists)) {
                throw new ModuleCannotInstallException((string) new Message(
                    'A custom vocab exists for "%s". Remove it or rename it before installing this module.', // @translate
                    pathinfo($filepath, PATHINFO_FILENAME)
                ));
            }
        }

        // Resource templates.
        foreach ($this->listFilesInDir($filepathData . 'resource-templates') as $filepath) {
            $exists = $this->checkResourceTemplate($filepath);
            if (is_null($exists)) {
                throw new ModuleCannotInstallException((string) new Message(
                    'A resource template exists for %s. Rename it or remove it before installing this module.', // @translate
                    pathinfo($filepath, PATHINFO_FILENAME)
                ));
            }
        }

        return true;
    }

    /**
     * Install all resources that are in the path data/ of a module.
     *
     * The data should have been checked first with checkAllResources().
     */
    public function createAllResources(string $module): self
    {
        $filepathData = OMEKA_PATH . '/modules/' . $module . '/data/';

        // Vocabularies.
        foreach ($this->listFilesInDir($filepathData . 'vocabularies', ['json']) as $filepath) {
            $data = file_get_contents($filepath);
            $data = json_decode($data, true);
            if (is_array($data)) {
                $this->createOrUpdateVocabulary($data, $module);
            }
        }

        // Custom vocabs (always create or update; non-destructive merge on update).
        foreach ($this->listFilesInDir($filepathData . 'custom-vocabs') as $filepath) {
            $this->createOrUpdateCustomVocab($filepath);
        }

        // Resource templates (create or update; merge without deleting).
        foreach ($this->listFilesInDir($filepathData . 'resource-templates') as $filepath) {
            $this->createOrUpdateResourceTemplate($filepath);
        }

        return $this;
    }

    /**
     * Check if a vocabulary exists and throws an exception if different.
     *
     * @throws \Omeka\Api\Exception\RuntimeException
     * @return bool False if not found, true if exists, null if a vocabulary
     * exists with the same prefix but a different uri.
     */
    public function checkVocabulary(array $vocabularyData, ?string $module = null): ?bool
    {
        $vocabularyData = $this->prepareVocabularyData($vocabularyData, $module);

        if (!empty($vocabularyData['update']['namespace_uri'])) {
            /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabularyRepresentation */
            $vocabularyRepresentation = $this->api->searchOne('vocabularies', ['namespace_uri' => $vocabularyData['update']['namespace_uri']])->getContent();
            if ($vocabularyRepresentation) {
                return true;
            }
        }

        $namespaceUri = $vocabularyData['vocabulary']['o:namespace_uri'];
        /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabularyRepresentation */
        $vocabularyRepresentation = $this->api->searchOne('vocabularies', ['namespace_uri' => $namespaceUri])->getContent();
        if ($vocabularyRepresentation) {
            return true;
        }

        // Check if the vocabulary have been already imported.
        $prefix = $vocabularyData['vocabulary']['o:prefix'];
        $vocabularyRepresentation = $this->api->searchOne('vocabularies', ['prefix' => $prefix])->getContent();
        if (!$vocabularyRepresentation) {
            return false;
        }

        // Check if it is the same vocabulary.
        // See createVocabulary() about the trim.
        if (rtrim($vocabularyRepresentation->namespaceUri(), '#/') === rtrim($namespaceUri, '#/')) {
            return true;
        }

        // It is another vocabulary with the same prefix.
        return null;
    }

    /**
     * Check if a resource template exists.
     *
     * Note: the vocabs of the resource template are not checked currently.
     *
     * @param string $filepath
     * @throws \Omeka\Api\Exception\RuntimeException
     * @return bool False if not found, true if exists.
     */
    public function checkResourceTemplate(string $filepath): bool
    {
        $data = json_decode(file_get_contents($filepath), true);
        if (!$data || empty($data['o:label'])) {
            return false;
        }

        $resp = $this->api->searchOne('resource_templates', ['label' => $data['o:label']]);
        $template = $resp ? $resp->getContent() : null;
        return !empty($template);
    }

    /**
     * Check if a custom vocab exists and throws an exception if different.
     *
     * @param string $filepath
     * @throws \Omeka\Api\Exception\RuntimeException
     * @return bool True if the custom vocab exists, false if not or module is
     * not installed, null if exists, but with different metadata (terms,
     * language).
     */
    public function checkCustomVocab(string $filepath): ?bool
    {
        $data = json_decode(file_get_contents($filepath), true);
        if (!$data || empty($data['o:label'])) {
            return false;
        }

        $label = $data['o:label'];
        try {
            // Custom vocab cannot be searched.
            /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
            $customVocab = $this->api->read('custom_vocabs', ['label' => $label])->getContent();
        } catch (NotFoundException $e) {
            return false;
        } catch (\Omeka\Api\Exception\BadRequestException $e) {
            return false;
        }

        if ($data['o:lang'] != $customVocab->lang()) {
            return null;
        }

        $newItemSet = empty($data['o:item_set']) ? 0 : (int) $data['o:item_set'];
        if ($newItemSet) {
            $existingItemSet = $customVocab->itemSet();
            return $existingItemSet && $newItemSet === $existingItemSet->id() ? true : null;
        }

        $newUris = $data['o:uris'] ?? [];
        if ($newUris) {
            $existingUris = $customVocab->uris();
            asort($newUris);
            asort($existingUris);
            return $newUris === $existingUris ? true : null;
        }

        $newTerms = $data['o:terms'] ?? [];
        $existingTerms = $customVocab->terms();
        // Compatibility with Omeka S v3.
        if (!is_array($existingTerms)) {
            $existingTerms = explode("\n", $existingTerms);
        }
        sort($newTerms);
        sort($existingTerms);
        return $newTerms === $existingTerms ? true : null;
    }

    /**
     * Create or update a vocabulary, with a check of its existence before.
     *
     * The file should have the full path if module is not set.
     *
     * @throws \Omeka\Api\Exception\RuntimeException
     */
    public function createOrUpdateVocabulary(
        array $vocabularyData,
        ?string $module = null
    ): bool {
        $vocabularyData = $this->prepareVocabularyData($vocabularyData, $module);
        $exists = $this->checkVocabulary($vocabularyData, $module);
        if ($exists === false) {
            return $this->createVocabulary($vocabularyData, $module);
        }
        return $this->updateVocabulary($vocabularyData, $module);
    }

    /**
     * Create a vocabulary, with a check of its existence before.
     *
     * @throws \Omeka\Api\Exception\RuntimeException
     * @return bool True if the vocabulary has been created, false if it exists
     * already, so it is not created twice.
     */
    public function createVocabulary(array $vocabularyData, ?string $module = null): bool
    {
        $vocabularyData = $this->prepareVocabularyData($vocabularyData, $module);

        // Check if the vocabulary have been already imported.
        $prefix = $vocabularyData['vocabulary']['o:prefix'];
        /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabularyRepresentation */
        $vocabularyRepresentation = $this->api->searchOne('vocabularies', ['prefix' => $prefix])->getContent();

        if ($vocabularyRepresentation) {
            // Check if it is the same vocabulary.
            // Note: in some cases, the uri of the ontology and the uri of the
            // namespace are mixed. So, the last character ("#" or "/") is
            // skipped for easier management.
            if (rtrim($vocabularyRepresentation->namespaceUri(), '#/') === rtrim($vocabularyData['vocabulary']['o:namespace_uri'], '#/')) {
                $message = new Message(
                    'The vocabulary "%s" was already installed and was kept.', // @translate
                    $vocabularyData['vocabulary']['o:label']
                );
                $messenger = $this->services->get('ControllerPluginManager')->get('messenger');
                $messenger->addWarning($message);
                return false;
            }

            // It is another vocabulary with the same prefix.
            throw new RuntimeException((string) new Message(
                'An error occured when adding the prefix "%s": another vocabulary exists with the same prefix. Resolve the conflict before installing this module.', // @translate
                $prefix
            ));
        }

        /** @var \Omeka\Stdlib\RdfImporter $rdfImporter */
        $rdfImporter = $this->services->get('Omeka\RdfImporter');

        try {
            $rdfImporter->import($vocabularyData['strategy'], $vocabularyData['vocabulary'], $vocabularyData['options']);
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            throw new RuntimeException((string) new Message(
                'An error occured when adding the prefix "%s" and the associated properties: %s', // @translate
                $prefix,
                $e->getMessage()
            ));
        }

        return true;
    }

    public function updateVocabulary(
        array $vocabularyData,
        ?string $module = null
    ): bool {
        $vocabularyData = $this->prepareVocabularyData($vocabularyData, $module);

        $prefix = $vocabularyData['vocabulary']['o:prefix'];
        $namespaceUri = $vocabularyData['vocabulary']['o:namespace_uri'];
        $oldNameSpaceUri = $vocabularyData['update']['namespace_uri'] ?? null;

        if ($oldNameSpaceUri) {
            /** @var \Omeka\Entity\Vocabulary $vocabulary */
            $vocabulary = $this->api->searchOne('vocabularies', ['namespace_uri' => $oldNameSpaceUri], ['responseContent' => 'resource'])->getContent();
        }
        // The old vocabulary may have been already updated.
        if (empty($vocabulary)) {
            $vocabulary = $this->api->searchOne('vocabularies', ['namespace_uri' => $namespaceUri], ['responseContent' => 'resource'])->getContent()
                ?: $this->api->searchOne('vocabularies', ['prefix' => $prefix], ['responseContent' => 'resource'])->getContent();
        }
        if (!$vocabulary) {
            return $this->createVocabulary($vocabularyData, $module);
        }

        // Omeka entities are not fluid.
        $vocabulary->setNamespaceUri($namespaceUri);
        $vocabulary->setPrefix($prefix);
        $vocabulary->setLabel($vocabularyData['vocabulary']['o:label']);
        $vocabulary->setComment($vocabularyData['vocabulary']['o:comment']);

        $entityManager = $this->services->get('Omeka\EntityManager');
        $entityManager->persist($vocabulary);
        $entityManager->flush();

        // Update the names first.
        foreach (['resource_classes', 'properties'] as $name) {
            if (empty($vocabularyData['update'][$name])) {
                continue;
            }
            foreach ($vocabularyData['update'][$name] as $oldLocalName => $newLocalName) {
                if ($oldLocalName === $newLocalName) {
                    continue;
                }
                $old = $this->api->searchOne($name, [
                    'vocabulary_id' => $vocabulary->getId(),
                    'local_name' => $oldLocalName,
                ], ['responseContent' => 'resource'])->getContent();
                if (!$old) {
                    continue;
                }
                $new = $this->api->searchOne($name, [
                    'vocabulary_id' => $vocabulary->getId(),
                    'local_name' => $newLocalName,
                ], ['responseContent' => 'resource'])->getContent();
                if ($new) {
                    $vocabularyData['replace'][$name][$oldLocalName] = $newLocalName;
                    continue;
                }
                $old->setLocalName($newLocalName);
                $entityManager->persist($old);
            }
        }
        $entityManager->flush();

        // Upgrade the classes and the properties.
        /** @var \Omeka\Stdlib\RdfImporter $rdfImporter */
        $rdfImporter = $this->services->get('Omeka\RdfImporter');

        try {
            $diff = $rdfImporter->getDiff($vocabularyData['strategy'], $vocabulary->getNamespaceUri(), $vocabularyData['options']);
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            throw new ModuleCannotInstallException((string) new Message(
                'An error occured when updating vocabulary "%s" and the associated properties: %s', // @translate
                $vocabularyData['vocabulary']['o:prefix'],
                $e->getMessage()
            ));
        }

        try {
            $diff = $rdfImporter->update($vocabulary->getId(), $diff);
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            throw new ModuleCannotInstallException((string) new Message(
                'An error occured when updating vocabulary "%s" and the associated properties: %s', // @translate
                $vocabularyData['vocabulary']['o:prefix'],
                $e->getMessage()
            ));
        }

        /** @var \Omeka\Entity\Property[] $properties */
        $owner = $vocabulary->getOwner();
        foreach (['resource_classes', 'properties'] as $name) {
            $members = $this->api->search($name, ['vocabulary_id' => $vocabulary->getId()], ['responseContent' => 'resource'])->getContent();
            foreach ($members as $member) {
                $member->setOwner($owner);
            }
        }

        $entityManager->flush();

        $this->replaceVocabularyMembers($vocabulary, $vocabularyData);

        return true;
    }

    protected function replaceVocabularyMembers(Vocabulary $vocabulary, array $vocabularyData): bool
    {
        $membersByLocalName = [];
        foreach (['resource_classes', 'properties'] as $name) {
            $membersByLocalName[$name] = $this->api->search($name, ['vocabulary_id' => $vocabulary->getId()], ['returnScalar' => 'localName'])->getContent();
            $membersByLocalName[$name] = array_flip($membersByLocalName[$name]);
        }

        // Update names of classes and properties in the case where they where
        // not updated before diff.
        $messenger = $this->services->get('ControllerPluginManager')->get('messenger');
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->services->get('Omeka\Connection');
        $hasReplace = false;
        foreach (['resource_classes', 'properties'] as $name) {
            if (empty($vocabularyData['replace'][$name])) {
                continue;
            }
            // Keep only members with good old local names and good new names.
            $members = array_intersect(
                array_intersect_key($vocabularyData['replace'][$name], $membersByLocalName[$name]),
                array_flip($membersByLocalName[$name])
            );
            if (empty($members)) {
                continue;
            }
            foreach ($members as $oldLocalName => $newLocalName) {
                if (
                    $oldLocalName === $newLocalName
                    || empty($membersByLocalName[$name][$oldLocalName])
                    || empty($membersByLocalName[$name][$newLocalName])
                ) {
                    continue;
                }
                $oldMemberId = $membersByLocalName[$name][$oldLocalName];
                $newMemberId = $membersByLocalName[$name][$newLocalName];
                // Update all places that uses the old name with new name, then
                // remove the old member.
                if ($name === 'resource_classes') {
                    $sqls = <<<SQL
UPDATE `resource`
SET `resource_class_id` = $newMemberId
WHERE `resource_class_id` = $oldMemberId;

UPDATE `resource_template`
SET `resource_class_id` = $newMemberId
WHERE `resource_class_id` = $oldMemberId;

DELETE FROM `resource_class`
WHERE `id` = $oldMemberId;

SQL;
                } else {
                    $sqls = <<<SQL
UPDATE `value`
SET `property_id` = $newMemberId
WHERE `property_id` = $oldMemberId;

UPDATE `resource_template_property`
SET `property_id` = $newMemberId
WHERE `property_id` = $oldMemberId;

DELETE FROM `property`
WHERE `id` = $oldMemberId;

SQL;
                }
                foreach (array_filter(explode(";\n", $sqls)) as $sql) {
                    $connection->executeStatement($sql);
                }
            }
            $hasReplace = true;
            // TODO Ideally, anywhere this option is used in the setting should be updated too.
            $message = new Message(
                'The following "%1$s" of the vocabulary "%2$s" were replaced: %3$s', // @translate
                $name,
                $vocabularyData['vocabulary']['o:label'],
                json_encode($members, 448)
            );
            $messenger->addWarning($message);
        }
        if ($hasReplace) {
            $entityManager = $this->services->get('Omeka\EntityManager');
            $entityManager->flush();

            $message = new Message('Resources, values and templates were updated, but you may check settings where the old ones were used.'); // @translate
            $messenger->addWarning($message);
        }

        return true;
    }

    /**
     * Create a resource template, with a check of its existence before.
     *
     * @todo Some checks of the resource template controller are skipped currently.
     *
     * @param string $filepath
     * @throws \Omeka\Api\Exception\RuntimeException
     * @return \Omeka\Api\Representation\ResourceTemplateRepresentation
     */
    public function createResourceTemplate(string $filepath): ResourceTemplateRepresentation
    {
        $data = json_decode(file_get_contents($filepath), true);

        // Check if the resource template exists, so it is not replaced.
        $label = $data['o:label'] ?? '';

        $resp = $this->api->searchOne('resource_templates', ['label' => $label]);
        $resourceTemplate = $resp ? $resp->getContent() : null;
        if ($resourceTemplate) {
            $message = new Message(
                'The resource template named "%s" is already available and is skipped.', // @translate
                $label
            );
            $messenger = $this->services->get('ControllerPluginManager')->get('messenger');
            $messenger->addWarning($message);
            return $resourceTemplate;
        }

        // The check sets the internal ids of classes, properties and data types
        // and converts old data types into multiple data types and prepare
        // other data types (mainly custom vocabs).
        $data = $this->flagValid($data);

        // Process import.
        $response = $this->api->create('resource_templates', $data);
        if (!$response) {
            throw new RuntimeException(
                (string) new Message(
                    'Failed to create resource template "%s".', // @translate
                    $label
                )
            );
        }
        return $response->getContent();
    }

    /**
     * Create or update a resource template by label (non-destructive merge on update).
     *
     * @param string $filepath
     * @return \Omeka\Api\Representation\ResourceTemplateRepresentation
     * @throws \Omeka\Api\Exception\RuntimeException
     */
    public function createOrUpdateResourceTemplate(string $filepath): ResourceTemplateRepresentation
    {
        $data = json_decode(file_get_contents($filepath), true);
        if (!$data || empty($data['o:label'])) {
            throw new RuntimeException(
                (string) new Message(
                    'Resource template file "%s" missing or invalid.', // @translate
                    basename($filepath)
                )
            );
        }

        $label = $data['o:label'];
        /** @var \Omeka\Api\Representation\ResourceTemplateRepresentation|null $existing */
        $resp = $this->api->searchOne('resource_templates', ['label' => $label]);
        $existing = null;

        if ($resp) {
            $content = $resp->getContent();

            // Handle case where API returns an ID instead of full object
            if (is_int($content) || (is_string($content) && is_numeric($content))) {
                // API returned an ID, fetch the full object
                try {
                    $existing = $this->api->read('resource_templates', $content)->getContent();
                } catch (\Exception $e) {
                    throw new RuntimeException(
                        (string) new Message(
                            'Failed to read resource template with ID %s for "%s": %s', // @translate
                            $content,
                            $label,
                            $e->getMessage()
                        )
                    );
                }
            } elseif (is_object($content) && method_exists($content, 'id')) {
                // API returned the full object as expected
                $existing = $content;
            } elseif (is_null($content)) {
                // API returned null, meaning resource template doesn't exist - this is normal for creation
                $existing = null;
            } else {
                // Simple error for unexpected API responses (no debug dump to avoid memory exhaustion)
                $contentType = is_object($content) ? get_class($content) : gettype($content);
                throw new RuntimeException(
                    (string) new Message(
                        'Unexpected API response for resource template "%s": expected object, ID, or NULL, got %s.', // @translate
                        $label,
                        $contentType
                    )
                );
            }
        }

        // Final validation that we have a proper object
        if ($existing && !method_exists($existing, 'id')) {
            throw new RuntimeException(
                (string) new Message(
                    'Invalid resource template object for "%s": missing id() method (type: %s)', // @translate
                    $label,
                    is_object($existing) ? get_class($existing) : gettype($existing)
                )
            );
        }

        // Normalize/validate incoming (sets ids & data types).
        $incoming = $this->flagValid($data);

        if (!$existing) {
            $response = $this->api->create('resource_templates', $incoming);
            if (!$response) {
                throw new RuntimeException(
                    (string) new Message(
                        'Failed to create resource template "%s".', // @translate
                        $label
                    )
                );
            }
            return $response->getContent();
        }

        // Merge existing + incoming via helper.
        /** @var ResourceTemplateMergeHelper $rtHelper */
        $rtHelper = new ResourceTemplateMergeHelper();
        $existingArr = $existing->jsonSerialize();
        $merged = $rtHelper->merge($existingArr, $incoming);

        // Clean up duplicate and invalid properties in merged payload
        $originalPropertyCount = count($merged['o:resource_template_property'] ?? []);
        $seenProperties = [];
        $validProperties = [];

        foreach ($merged['o:resource_template_property'] ?? [] as $i => $property) {
            $propertyId = $property['o:property']['o:id'] ?? null;

            if (!$propertyId) {
                // Skip properties without valid property ID
                continue;
            }

            // Check if we've seen this property ID before
            if (isset($seenProperties[$propertyId])) {
                // We have a duplicate - merge the data or skip incomplete ones
                $existingPropertyIndex = $seenProperties[$propertyId];
                $existingProperty = $validProperties[$existingPropertyIndex];

                // Prefer the one with more complete vocabulary metadata
                $hasVocabInfo = !empty($property['vocabulary_namespace_uri']) &&
                    !empty($property['local_name']) &&
                    !empty($property['vocabulary_prefix']);

                $existingHasVocabInfo = !empty($existingProperty['vocabulary_namespace_uri']) &&
                    !empty($existingProperty['local_name']) &&
                    !empty($existingProperty['vocabulary_prefix']);

                if ($hasVocabInfo && !$existingHasVocabInfo) {
                    // Replace existing with more complete version
                    $validProperties[$existingPropertyIndex] = $property;
                }
                // Otherwise keep the existing one
                continue;
            }

            // Validate required fields for new property
            $hasRequiredFields = !empty($property['o:property']['o:id']) &&
                !empty($property['vocabulary_namespace_uri']) &&
                !empty($property['local_name']) &&
                !empty($property['vocabulary_prefix']);

            if ($hasRequiredFields) {
                $seenProperties[$propertyId] = count($validProperties);
                $validProperties[] = $property;
            }
        }

        // Update merged payload with cleaned properties
        $merged['o:resource_template_property'] = array_values($validProperties);





        // Ensure $existing is properly resolved to an object before update
        if (!is_object($existing)) {
            throw new RuntimeException(
                (string) new Message(
                    'Expected object for existing resource template "%s", got %s', // @translate
                    $label,
                    gettype($existing)
                )
            );
        }

        if (!method_exists($existing, 'id')) {
            throw new RuntimeException(
                (string) new Message(
                    'Resource template object for "%s" missing id() method (class: %s)', // @translate
                    $label,
                    get_class($existing)
                )
            );
        }

        // Ensure all objects are converted to arrays for API compatibility
        $merged = $this->deepArrayConvert($merged);

        try {
            $response = $this->api->update(
                'resource_templates',
                $existing->id(),
                $merged,
                [],
                ['isPartial' => false]
            );
            if (!$response) {
                throw new RuntimeException(
                    (string) new Message(
                        'Failed to update resource template "%s".', // @translate
                        $label
                    )
                );
            }
            return $response->getContent();
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            // Surface validation errors to help debugging.
            $errors = method_exists($e, 'getErrorStore') ? $e->getErrorStore()->getErrors() : [];
            $errFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rt-errors-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '-' . date('Ymd-His') . '.log';

            // Memory-safe error logging - limit size and use JSON for better structure
            $errorOutput = '';
            if (is_array($errors) && count($errors) > 0) {
                $errorOutput = json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if (strlen($errorOutput) > 10000) {
                    $errorOutput = substr($errorOutput, 0, 10000) . "\n...[truncated - output too large]";
                }
            } else {
                $errorOutput = 'No detailed errors available';
            }

            @file_put_contents($errFile, $errorOutput . PHP_EOL . 'Message: ' . $e->getMessage());

            $messenger = $this->services->get('ControllerPluginManager')->get('messenger');
            $messenger->addError(new Message(
                'Validation failed updating RT "%1$s". See %2$s for details.', // @translate
                $label,
                $errFile
            ));

            throw new RuntimeException(
                (string) new Message(
                    'Failed to update resource template "%s".', // @translate
                    $label
                )
            );
        } catch (\Throwable $e) {
            // Last-resort debug info.
            $errFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rt-exception-' . preg_replace('/[^a-z0-9]+/i', '-', $label) . '-' . date('Ymd-His') . '.log';
            @file_put_contents($errFile, (string) $e);

            $messenger = $this->services->get('ControllerPluginManager')->get('messenger');
            $messenger->addError(new Message(
                'Exception updating RT "%1$s". See %2$s for details. %3$s', // @translate
                $label,
                $errFile,
                $e->getMessage()
            ));

            throw new RuntimeException(
                (string) new Message(
                    'Failed to update resource template "%s".', // @translate
                    $label
                )
            );
        }
    }

    /**
     * Create or update a custom vocab.
     *
     * @param string $filepath
     * @return ?\CustomVocab\Api\Representation\CustomVocabRepresentation|null
     */
    public function createOrUpdateCustomVocab(string $filepath): ?\CustomVocab\Api\Representation\CustomVocabRepresentation
    {
        try {
            return $this->updateCustomVocab($filepath);
        } catch (RuntimeException $e) {
            return $this->createCustomVocab($filepath);
        }
    }

    /**
     * Create a custom vocab.
     *
     * @param string $filepath
     * @return \CustomVocab\Api\Representation\CustomVocabRepresentation|null
     */
    public function createCustomVocab(string $filepath): ?\CustomVocab\Api\Representation\CustomVocabRepresentation
    {
        $data = json_decode(file_get_contents($filepath), true);
        if (!$data) {
            return null;
        }
        $data['o:terms'] = implode(PHP_EOL, $data['o:terms'] ?? []);
        try {
            return $this->api->create('custom_vocabs', $data)->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Flag members and data types as valid.
     *
     * Copy of the method of the resource template controller (with services)
     * and remove of keys "data_types" inside "o:data".
     *
     * @see \AdvancedResourceTemplate\Controller\Admin\ResourceTemplateControllerDelegator::flagValid()
     *
     * All members start as invalid until we determine whether the corresponding
     * vocabulary and member exists in this installation. All data types start
     * as "Default" (i.e. none declared) until we determine whether they match
     * the native types (literal, uri, resource).
     *
     * We flag a valid vocabulary by adding [vocabulary_prefix] to the member; a
     * valid class by adding [o:id]; and a valid property by adding
     * [o:property][o:id]. We flag a valid data type by adding [o:data_type] to
     * the property. By design, the API will only hydrate members and data types
     * that are flagged as valid.
     *
     * @todo Manage direct import of data types from Value Suggest and other modules.
     *
     * @param array $import
     * @return array|false
     */
    protected function flagValid(iterable $import)
    {
        $messenger = $this->services->get('ControllerPluginManager')->get('messenger');
        $vocabs = [];

        // The controller plugin Api is used to allow to search one resource.
        $api = $this->services->get('ControllerPluginManager')->get('api');

        $getVocab = function ($namespaceUri) use (&$vocabs, $api) {
            if (isset($vocabs[$namespaceUri])) {
                return $vocabs[$namespaceUri];
            }
            $resp = $api->searchOne('vocabularies', [
                'namespace_uri' => $namespaceUri,
            ]);
            $vocab = $resp ? $resp->getContent() : null;
            if ($vocab) {
                $vocabs[$namespaceUri] = $vocab;
                return $vocab;
            }
            return false;
        };

        $getDataTypesByName = function ($dataTypesNameLabels) {
            $result = [];
            foreach ($dataTypesNameLabels ?? [] as $dataType) {
                $result[$dataType['name']] = $dataType;
            }
            return $result;
        };

        // Manage core data types and common modules ones.
        $getKnownDataType = function ($dataTypeNameLabel) use ($api, $messenger): ?string {
            // Core and well-known module data types pass through unchanged.
            if (
                in_array($dataTypeNameLabel['name'], [
                    'literal',
                    'resource',
                    'resource:item',
                    'resource:itemset',
                    'resource:media',
                    'uri',
                    // DataTypeGeometry
                    'geography',
                    'geography:coordinates',
                    'geometry',
                    'geometry:coordinates',
                    'geometry:position',
                    // TODO Deprecated for v4.
                    'geometry:geometry',
                    'geometry:geography',
                    'geometry:geography:coordinates',
                    // DataTypeRdf.
                    'boolean',
                    'html',
                    'xml',
                    // DataTypePlace.
                    'place',
                    // NumericDataTypes
                    'numeric:timestamp',
                    'numeric:integer',
                    'numeric:duration',
                    'numeric:interval',
                ], true)
                || mb_substr((string) $dataTypeNameLabel['name'], 0, 13) === 'valuesuggest:'
                || mb_substr((string) $dataTypeNameLabel['name'], 0, 16) === 'valuesuggestall:'
            ) {
                return $dataTypeNameLabel['name'];
            }

            // Handle custom vocabs: allow either "customvocab:{Label}" or "customvocab:123".
            if (mb_substr((string) $dataTypeNameLabel['name'], 0, 12) === 'customvocab:') {
                $name = (string) $dataTypeNameLabel['name'];
                $label = $dataTypeNameLabel['label'] ?? null;

                // If no explicit label, try to extract from "customvocab:{Label}".
                if (!$label && preg_match('/^customvocab:\{(.+)\}$/u', $name, $m)) {
                    $label = trim($m[1]);
                }

                // If both numeric id and label are present, prefer label resolution.
                if ($label) {
                    try {
                        /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
                        $customVocab = $api->read('custom_vocabs', ['label' => $label])->getContent();
                        return 'customvocab:' . $customVocab->id();
                    } catch (\Omeka\Api\Exception\NotFoundException $e) {
                        // Log and drop the datatype (do not throw).
                        $messenger->addWarning(new \Omeka\Stdlib\Message(
                            'Custom vocab "%s" was referenced in a resource template but was not found. The corresponding data type has been removed.', // @translate
                            $label
                        ));
                        return null;
                    }
                }

                // No label to resolve by — permit the numeric id as-is only if present.
                if (preg_match('/^customvocab:(\d+)$/', $name)) {
                    // Keep the numeric reference unchanged.
                    return $name;
                }

                // Unknown/unsupported customvocab form: log and drop.
                $messenger->addWarning(new \Omeka\Stdlib\Message(
                    'A custom vocab reference "%s" could not be resolved and has been removed from the template.', // @translate
                    $name
                ));
                return null;
            }

            return null;
        };

        if (isset($import['o:resource_class'])) {
            if ($vocab = $getVocab($import['o:resource_class']['vocabulary_namespace_uri'])) {
                $import['o:resource_class']['vocabulary_prefix'] = $vocab->prefix();
                $resp = $api->searchOne('resource_classes', [
                    'vocabulary_namespace_uri' => $import['o:resource_class']['vocabulary_namespace_uri'],
                    'local_name' => $import['o:resource_class']['local_name'],
                ]);
                $class = null;
                if ($resp) {
                    $content = $resp->getContent();
                    if (is_int($content) || (is_string($content) && is_numeric($content))) {
                        // API returned an ID, use it directly
                        $import['o:resource_class']['o:id'] = (int) $content;
                    } elseif (is_object($content) && method_exists($content, 'id')) {
                        // API returned the full object
                        $import['o:resource_class']['o:id'] = $content->id();
                    }
                }
            }
        }

        foreach (['o:title_property', 'o:description_property'] as $property) {
            if (isset($import[$property])) {
                if ($vocab = $getVocab($import[$property]['vocabulary_namespace_uri'])) {
                    $import[$property]['vocabulary_prefix'] = $vocab->prefix();
                    $respP = $api->searchOne('properties', [
                        'vocabulary_namespace_uri' => $import[$property]['vocabulary_namespace_uri'],
                        'local_name' => $import[$property]['local_name'],
                    ]);
                    if ($respP) {
                        $content = $respP->getContent();
                        if (is_int($content) || (is_string($content) && is_numeric($content))) {
                            // API returned an ID, use it directly
                            $import[$property]['o:id'] = (int) $content;
                        } elseif (is_object($content) && method_exists($content, 'id')) {
                            // API returned the full object
                            $import[$property]['o:id'] = $content->id();
                        }
                    }
                }
            }
        }

        foreach ($import['o:resource_template_property'] as $key => $property) {
            if ($vocab = $getVocab($property['vocabulary_namespace_uri'])) {
                $import['o:resource_template_property'][$key]['vocabulary_prefix'] = $vocab->prefix();
                $respProp = $api->searchOne('properties', [
                    'vocabulary_namespace_uri' => $property['vocabulary_namespace_uri'],
                    'local_name' => $property['local_name'],
                ]);
                $propId = null;
                if ($respProp) {
                    $content = $respProp->getContent();
                    if (is_int($content) || (is_string($content) && is_numeric($content))) {
                        // API returned an ID, use it directly
                        $propId = (int) $content;
                    } elseif (is_object($content) && method_exists($content, 'id')) {
                        // API returned the full object
                        $propId = $content->id();
                    }
                }
                if ($propId) {
                    $import['o:resource_template_property'][$key]['o:property'] = ['o:id' => $propId];
                    // Check the deprecated "data_type_name" if needed and
                    // normalize it.
                    if (!array_key_exists('data_types', $import['o:resource_template_property'][$key])) {
                        if (
                            !empty($import['o:resource_template_property'][$key]['data_type_name'])
                            && !empty($import['o:resource_template_property'][$key]['data_type_label'])
                        ) {
                            $import['o:resource_template_property'][$key]['data_types'] = [[
                                'name' => $import['o:resource_template_property'][$key]['data_type_name'],
                                'label' => $import['o:resource_template_property'][$key]['data_type_label'],
                            ]];
                        } else {
                            $import['o:resource_template_property'][$key]['data_types'] = [];
                        }
                    }
                    unset($import['o:resource_template_property'][$key]['data_type_name']);
                    unset($import['o:resource_template_property'][$key]['data_type_label']);
                    $import['o:resource_template_property'][$key]['data_types'] = $getDataTypesByName($import['o:resource_template_property'][$key]['data_types']);
                    // Prepare the list of standard data types.
                    $import['o:resource_template_property'][$key]['o:data_type'] = [];
                    foreach ($import['o:resource_template_property'][$key]['data_types'] as $name => $dataTypeNameLabel) {
                        $known = $getKnownDataType($dataTypeNameLabel);
                        if ($known) {
                            $import['o:resource_template_property'][$key]['o:data_type'][] = $known;
                            $import['o:resource_template_property'][$key]['data_types'][$name]['name'] = $known;
                        }
                    }
                    $import['o:resource_template_property'][$key]['o:data_type'] = array_unique($import['o:resource_template_property'][$key]['o:data_type']);
                    // Prepare the list of standard data types for duplicated
                    // properties (only one most of the time, that is the main).
                    $import['o:resource_template_property'][$key]['o:data'] = array_values($import['o:resource_template_property'][$key]['o:data'] ?? []);
                    $import['o:resource_template_property'][$key]['o:data'][0]['data_types'] = $import['o:resource_template_property'][$key]['data_types'] ?? [];
                    $import['o:resource_template_property'][$key]['o:data'][0]['o:data_type'] = $import['o:resource_template_property'][$key]['o:data_type'] ?? [];
                    $first = true;
                    foreach ($import['o:resource_template_property'][$key]['o:data'] as $k => $rtpData) {
                        if ($first) {
                            $first = false;
                            // Specific to the installer.
                            unset($import['o:resource_template_property'][$key]['o:data'][$k]['data_types']);
                            continue;
                        }
                        // Prepare the list of standard data types if any.
                        $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'] = [];
                        if (empty($rtpData['data_types'])) {
                            continue;
                        }
                        $import['o:resource_template_property'][$key]['o:data'][$k]['data_types'] = $getDataTypesByName($import['o:resource_template_property'][$key]['o:data'][$k]['data_types']);
                        foreach ($import['o:resource_template_property'][$key]['o:data'][$k]['data_types'] as $name => $dataTypeNameLabel) {
                            $known = $getKnownDataType($dataTypeNameLabel);
                            if ($known) {
                                $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'][] = $known;
                                $import['o:resource_template_property'][$key]['o:data'][$k]['data_types'][$name]['name'] = $known;
                            }
                        }
                        $import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type'] = array_unique($import['o:resource_template_property'][$key]['o:data'][$k]['o:data_type']);
                        // Specific to the installer.
                        unset($import['o:resource_template_property'][$key]['o:data'][$k]['data_types']);
                    }
                }
            }
        }

        return $import;
    }

    /**
     * Update a vocabulary, with a check of its existence before.
     *
     * @param string $filepath
     * @throws \Omeka\Api\Exception\RuntimeException
     * @return \CustomVocab\Api\Representation\CustomVocabRepresentation
     */
    public function updateCustomVocab(string $filepath): \CustomVocab\Api\Representation\CustomVocabRepresentation
    {
        $data = json_decode(file_get_contents($filepath), true);

        $label = $data['o:label'];
        try {
            /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
            $customVocab = $this->api->read('custom_vocabs', ['label' => $label])->getContent();
        } catch (NotFoundException $e) {
            throw new RuntimeException(
                (string) new Message(
                    'The custom vocab named "%s" is not available.', // @translate
                    $label
                )
            );
        }

        $newItemSet = empty($data['o:item_set']) ? 0 : (int) $data['o:item_set'];
        $newUris = $data['o:uris'] ?? [];
        $newTerms = $data['o:terms'] ?? [];

        /** @var CustomVocabMergeHelper $cvHelper */
        $cvHelper = new CustomVocabMergeHelper();
        $payload = $cvHelper->buildUpdatePayload($customVocab, [
            'o:label' => $label,
            'o:item_set' => $newItemSet ?: null,
            'o:uris' => $newUris,
            'o:terms' => $newTerms,
        ]);

        $this->api->update('custom_vocabs', $customVocab->id(), $payload, [], ['isPartial' => true]);

        return $this->api->read('custom_vocabs', $customVocab->id())->getContent();
    }

    /**
     * Remove a vocabulary by its prefix.
     *
     * @param string $prefix
     * @return self
     */
    public function removeVocabulary(string $prefix): self
    {
        // The vocabulary may have been removed manually before.
        $resource = $this->api->searchOne('vocabularies', ['prefix' => $prefix])->getContent();
        if ($resource) {
            try {
                $this->api->delete('vocabularies', $resource->id());
            } catch (\Exception $e) {
            }
        }
        return $this;
    }

    /**
     * Remove a resource template by its label.
     *
     * @param string $label
     * @return self
     */
    public function removeResourceTemplate(string $label): self
    {
        // The resource template may be renamed or removed manually before.
        try {
            $resource = $this->api->read('resource_templates', ['label' => $label])->getContent();
            $this->api->delete('resource_templates', $resource->id());
        } catch (\Exception $e) {
        }
        return $this;
    }

    /**
     * Remove a custom vocab by its label.
     *
     * @param string $label
     * @return self
     */
    public function removeCustomVocab(string $label): self
    {
        // The custom vocab may be renamed or removed manually before.
        try {
            $resource = $this->api->read('custom_vocabs', ['label' => $label])->getContent();
            $this->api->delete('custom_vocabs', $resource->id());
        } catch (NotFoundException $e) {
        }
        return $this;
    }

    /**
     * Check the version of a module.
     *
     * It is recommended to use checkModuleAvailability(), that manages the fact
     * that the module may be required or not.
     */
    protected function isModuleVersionAtLeast(string $module, string $version): bool
    {
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $this->services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($module);
        if (!$module) {
            return false;
        }

        $moduleVersion = $module->getIni('version');
        return $moduleVersion
            ? version_compare($moduleVersion, $version, '>=')
            : false;
    }

    /**
     * Get the full file path inside the data directory. The path may be an url.
     */
    public function fileDataPath(string $fileOrUrl, ?string $module = null, ?string $dataDirectory = null): ?string
    {
        if (!$fileOrUrl) {
            return null;
        }

        $fileOrUrl = trim($fileOrUrl);
        if (strpos($fileOrUrl, 'https://') !== false || strpos($fileOrUrl, 'http://') !== false) {
            return $fileOrUrl;
        }

        // Check if this is already the full path.
        $modulesPath = OMEKA_PATH . '/modules/';
        if (strpos($fileOrUrl, $modulesPath) === 0) {
            $filepath = $fileOrUrl;
        } elseif (!$module) {
            return null;
        } else {
            $filepath = $modulesPath . $module . '/data/' . ($dataDirectory ? $dataDirectory . '/' : '') . $fileOrUrl;
        }

        if (file_exists($filepath) && filesize($filepath) && is_readable($filepath)) {
            return $filepath;
        }

        return null;
    }

    protected function prepareVocabularyData(array $vocabularyData, ?string $module = null): array
    {
        if (!empty($vocabularyData['is_checked'])) {
            return $vocabularyData;
        }

        $filepath = $vocabularyData['options']['file'] ?? $vocabularyData['options']['url']
            ?? $vocabularyData['file'] ?? $vocabularyData['url'] ?? null;
        if (!$filepath) {
            throw new RuntimeException((string) new Message(
                'No file or url set for the vocabulary.' // @translate
            ));
        }

        $filepath = trim($filepath);
        $isUrl = strpos($filepath, 'http:/') === 0 || strpos($filepath, 'https:/') === 0;
        if ($isUrl) {
            $fileContent = file_get_contents($filepath);
            if (empty($fileContent)) {
                throw new RuntimeException((string) new Message(
                    'The file "%s" cannot be read. Check the url.', // @translate
                    strpos($filepath, '/') === 0 ? basename($filepath) : $filepath
                ));
            }
            $vocabularyData['strategy'] = 'url';
            $vocabularyData['options']['url'] = $filepath;
        } else {
            $filepath = $this->fileDataPath($filepath, $module, 'vocabularies');
            if (!$filepath) {
                throw new RuntimeException((string) new Message(
                    'The file "%s" cannot be read. Check the file.', // @translate
                    strpos($filepath, '/') === 0 ? basename($filepath) : $filepath
                ));
            }
            $vocabularyData['strategy'] = 'file';
            $vocabularyData['options']['file'] = $filepath;
        }

        if (isset($vocabularyData['format'])) {
            $vocabularyData['options']['format'] = $vocabularyData['format'];
        }
        unset(
            $vocabularyData['file'],
            $vocabularyData['url'],
            $vocabularyData['format']
        );

        $namespaceUri = $vocabularyData['vocabulary']['o:namespace_uri'] ?? '';
        $prefix = $vocabularyData['vocabulary']['o:prefix'] ?? '';
        if (!$namespaceUri || !$prefix) {
            throw new RuntimeException((string) new Message(
                'A vocabulary must have a namespace uri and a prefix.' // @translate
            ));
        }

        $vocabularyData['is_checked'] = true;
        return $vocabularyData;
    }

    /**
     * List filtered files in a directory, not recursively, and without subdirs.
     *
     * Unreadable and empty files are skipped.
     *
     * @param string $dirpath
     * @param array $extensions
     * @return array
     */
    protected function listFilesInDir($dirpath, iterable $extensions = []): array
    {
        if (empty($dirpath) || !file_exists($dirpath) || !is_dir($dirpath) || !is_readable($dirpath)) {
            return [];
        }
        $list = array_filter(array_map(function ($file) use ($dirpath) {
            return $dirpath . DIRECTORY_SEPARATOR . $file;
        }, scandir($dirpath)), function ($file) {
            return is_file($file) && is_readable($file) && filesize($file);
        });
        if ($extensions) {
            $list = array_filter($list, function ($file) use ($extensions) {
                return in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions);
            });
        }
        return array_values($list);
    }

    /**
     * Recursively convert objects to arrays to ensure API compatibility.
     * 
     * This is needed because merged resource template data may contain 
     * ResourceReference objects that need to be converted to arrays
     * before being sent to the API.
     */
    protected function deepArrayConvert($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->deepArrayConvert($value);
            }
            return $result;
        } elseif (is_object($data)) {
            // Convert objects to arrays
            if (method_exists($data, 'jsonSerialize')) {
                return $this->deepArrayConvert($data->jsonSerialize());
            } elseif (method_exists($data, 'toArray')) {
                return $this->deepArrayConvert($data->toArray());
            } else {
                // Fallback: convert object properties to array
                return $this->deepArrayConvert(get_object_vars($data));
            }
        }

        // Return primitive values as-is
        return $data;
    }
}
