<?php

/**
 * @file
 * Deploy hooks for the Eläintehtaat module.
 */

declare(strict_types=1);

/**
 * Translate the browse and projects page paths to English and Swedish.
 */
function elaintehtaat_deploy_translate_view_paths(): string {
  // The view paths are Finnish; the other languages get an alias of their own.
  $aliases = [
    '/selaa' => ['en' => '/browse', 'sv' => '/bladdra'],
    '/projektit' => ['en' => '/projects', 'sv' => '/projekt'],
  ];

  $created = _elaintehtaat_create_path_aliases($aliases);
  return sprintf('Created %d view path aliases.', $created);
}

/**
 * Translate the albums page path and move "About us" after its menu link.
 */
function elaintehtaat_deploy_albums_page(): string {
  $created = _elaintehtaat_create_path_aliases([
    '/albumit' => ['en' => '/albums', 'sv' => '/album'],
  ]);

  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  // Projects, albums, then "About us": the albums link, which the view
  // provides, has weight 0, which "About us" had too.
  foreach ($storage->loadByProperties(['uuid' => '67202b1d-287f-4c1f-8d6d-aca5ae089c3f']) as $about) {
    $about->set('weight', 1)->save();
  }

  return sprintf('Created %d albums page path aliases.', $created);
}

/**
 * Delete the hand-made browse menu link that the browse view now provides.
 */
function elaintehtaat_deploy_browse_menu_link(): string {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('menu_name', 'main')
    ->condition('link.uri', [
      'internal:/selaa',
      'internal:/browse',
      'internal:/bladdra',
      'route:view.browse.page_1',
    ], 'IN')
    ->execute();
  $storage->delete($storage->loadMultiple($ids));

  return sprintf('Deleted %d browse menu links.', count($ids));
}

/**
 * Delete the Flickr images that have no named photographer.
 */
function elaintehtaat_deploy_delete_uncredited_flickr_images(array &$sandbox): string {
  // The images listed in data/flickr-images-to-delete.txt have no
  // photographer, or an anonymous one. Their albums lose them, an album left
  // without media is deleted, and then the media and files go. Deleting a file
  // also deletes it and its image style derivatives from the bucket.
  $entity_type_manager = \Drupal::entityTypeManager();
  $storages = [
    'albums' => $entity_type_manager->getStorage('node'),
    'media' => $entity_type_manager->getStorage('media'),
    'files' => $entity_type_manager->getStorage('file'),
  ];

  if (!isset($sandbox['queue'])) {
    $lines = file(__DIR__ . '/data/flickr-images-to-delete.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $uris = [];
    foreach ($lines as $line) {
      if (!str_starts_with($line, '#')) {
        $uris[] = 'public://flickr/' . trim($line);
      }
    }

    $fids = $storages['files']->getQuery()
      ->accessCheck(FALSE)
      ->condition('uri', $uris, 'IN')
      ->execute();
    $mids = $fids ? $storages['media']->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', 'image')
      ->condition('field_media_image.target_id', $fids, 'IN')
      ->execute() : [];
    $nids = $mids ? $storages['albums']->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'album')
      ->condition('field_album_media.target_id', $mids, 'IN')
      ->execute() : [];

    // Albums first, so that no album refers to deleted media, then the media,
    // then the files the media used.
    $sandbox['queue'] = [
      'albums' => array_values($nids),
      'media' => array_values($mids),
      'files' => array_values($fids),
    ];
    $sandbox['mids'] = array_fill_keys($mids, TRUE);
    $sandbox['total'] = count($nids) + count($mids) + count($fids);
    $sandbox['done'] = 0;
    $sandbox['counts'] = [
      'albums deleted' => 0,
      'albums updated' => 0,
      'media' => 0,
      'files' => 0,
    ];
  }

  foreach ($sandbox['queue'] as $type => $ids) {
    if (!$ids) {
      continue;
    }
    $batch = array_splice($sandbox['queue'][$type], 0, 50);
    $entities = $storages[$type]->loadMultiple($batch);

    if ($type === 'albums') {
      /** @var \Drupal\node\NodeInterface $album */
      foreach ($entities as $album) {
        // Drop references to media that is already gone, too.
        $items = $album->get('field_album_media');
        $items->filter(fn ($item) => !isset($sandbox['mids'][$item->target_id]) && $item->entity);
        if ($items->isEmpty()) {
          $album->delete();
          $sandbox['counts']['albums deleted']++;
          continue;
        }
        $album->setNewRevision();
        $album->setRevisionLogMessage('Removed the images that have no named photographer.');
        $album->save();
        $sandbox['counts']['albums updated']++;
      }
    }
    else {
      $storages[$type]->delete($entities);
      $sandbox['counts'][$type] += count($entities);
    }

    $sandbox['done'] += count($batch);
    break;
  }

  $sandbox['#finished'] = $sandbox['total'] ? $sandbox['done'] / $sandbox['total'] : 1;
  if ($sandbox['#finished'] < 1) {
    return '';
  }

  $counts = $sandbox['counts'];
  return sprintf(
    'Deleted %d albums, %d media and %d files; removed images from %d albums.',
    $counts['albums deleted'],
    $counts['media'],
    $counts['files'],
    $counts['albums updated'],
  );
}

/**
 * Creates the path aliases that do not exist yet.
 *
 * @param array<string, array<string, string>> $aliases
 *   The aliases, keyed by system path and then by language code.
 *
 * @return int
 *   The number of aliases created.
 */
function _elaintehtaat_create_path_aliases(array $aliases): int {
  $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $created = 0;
  foreach ($aliases as $path => $translations) {
    foreach ($translations as $langcode => $alias) {
      if ($storage->loadByProperties(['path' => $path, 'langcode' => $langcode])) {
        continue;
      }
      $storage->create([
        'path' => $path,
        'alias' => $alias,
        'langcode' => $langcode,
      ])->save();
      $created++;
    }
  }
  return $created;
}
