<?php

/**
 * @file
 * Deploy hooks for the Eläintehtaat module.
 */

declare(strict_types=1);

use Drupal\node\NodeInterface;

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
        $album->setRevisionCreationTime(\Drupal::time()->getRequestTime());
        $album->setRevisionUserId(1);
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
 * Move the last image of "Muut kuvat" into its Evijärvi album.
 */
function elaintehtaat_deploy_move_evijarvi_image(): string {
  // Flickr listed photo 54027757364 in no album, so it ended up in "Muut
  // kuvat". It belongs to "Evijärvi, Turkistarha (1), Elo-, loka- ja
  // marraskuu 2023": same farm, photographer and numbering, uploaded with
  // that album's photos. "Muut kuvat" holds nothing else, so it goes.
  $entity_type_manager = \Drupal::entityTypeManager();
  $node_storage = $entity_type_manager->getStorage('node');
  $media = current($entity_type_manager->getStorage('media')->loadByProperties(['uuid' => '840fb763-4ba7-4c3d-8e3a-9e15594e9640']));
  $from = current($node_storage->loadByProperties(['uuid' => '1fdf6c96-4703-483f-afcf-698232d90404']));
  $to = current($node_storage->loadByProperties(['uuid' => 'cfb898e8-372e-4cae-ab0f-45e844c97291']));
  if (!$media || !$to instanceof NodeInterface) {
    return 'The image or its album is gone; nothing moved.';
  }
  $mid = (int) $media->id();
  $time = \Drupal::time()->getRequestTime();

  $in_target = in_array($mid, array_map('intval', array_column($to->get('field_album_media')->getValue(), 'target_id')), TRUE);
  if (!$in_target) {
    $to->get('field_album_media')->appendItem(['target_id' => $mid]);
    $to->setNewRevision();
    $to->setRevisionCreationTime($time);
    $to->setRevisionUserId(1);
    $to->setRevisionLogMessage('Added the image that was in "Muut kuvat".');
    $to->save();
  }

  $other = 'is gone';
  if ($from instanceof NodeInterface) {
    $items = $from->get('field_album_media');
    $items->filter(fn ($item) => (int) $item->target_id !== $mid);
    if ($items->isEmpty()) {
      $from->delete();
      $other = 'was deleted';
    }
    else {
      $from->setNewRevision();
      $from->setRevisionCreationTime($time);
      $from->setRevisionUserId(1);
      $from->setRevisionLogMessage('Moved the Evijärvi image to its album.');
      $from->save();
      $other = 'keeps its other images';
    }
  }

  return sprintf('Moved media %d to "%s"; "Muut kuvat" %s.', $mid, $to->label(), $other);
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
