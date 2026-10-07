<?php

declare(strict_types=1);

namespace Drupal\Tests\pathologic\Kernel;

/**
 * Tests Pathologic functionality.
 *
 * @group pathologic
 */
class PathologicTest extends PathologicKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'pathologic_test',
  ];

  /**
   * Test all variations of URLs.
   */
  public function testPathologic() {
    global $_pathologic_script_path;

    // Start by testing our function to build protocol-relative URLs.
    $this->assertSame(
      '//example.com/foo/bar',
      _pathologic_url_to_protocol_relative('http://example.com/foo/bar'),
      'Protocol-relative URL creation with http:// URL'
    );
    $this->assertSame(
      '//example.org/baz',
      _pathologic_url_to_protocol_relative('https://example.org/baz'),
      'Protocol-relative URL creation with https:// URL'
    );

    // Build some paths to check against.
    $test_paths = [
      'foo' => [
        'path' => 'foo',
        'opts' => [],
      ],
      'foo/bar' => [
        'path' => 'foo/bar',
        'opts' => [],
      ],
      'foo/bar?baz' => [
        'path' => 'foo/bar',
        'opts' => ['query' => ['baz' => NULL]],
      ],
      'foo/bar?baz=qux' => [
        'path' => 'foo/bar',
        'opts' => ['query' => ['baz' => 'qux']],
      ],
      'foo/bar#baz' => [
        'path' => 'foo/bar',
        'opts' => ['fragment' => 'baz'],
      ],
      'foo/bar?baz=qux&amp;quux=quuux#quuuux' => [
        'path' => 'foo/bar',
        'opts' => [
          'query' => ['baz' => 'qux', 'quux' => 'quuux'],
          'fragment' => 'quuuux',
        ],
      ],
      'foo%20bar?baz=qux%26quux' => [
        'path' => 'foo bar',
        'opts' => [
          'query' => ['baz' => 'qux&quux'],
        ],
      ],
      '/' => [
        'path' => '<front>',
        'opts' => [],
      ],
    ];

    foreach (['full', 'proto-rel', 'path'] as $protocol_style) {
      $this->buildFormat([
        'settings_source' => 'local',
        'local_settings' => [
          'protocol_style' => $protocol_style,
          'keep_language_prefix' => TRUE,
        ],
      ]);
      $paths = [];
      foreach ($test_paths as $path => $args) {
        $args['opts']['absolute'] = $protocol_style !== 'path';
        $paths[$path] = $this->pathologicContentUrl($args['path'], $args['opts']);
        if ($protocol_style === 'proto-rel') {
          $paths[$path] = _pathologic_url_to_protocol_relative($paths[$path]);
        }
      }
      $clean = empty($_pathologic_script_path) ? 'Yes' : 'No';

      $this->assertSame(
        '<a href="' . $paths['foo'] . '"><img src="' . $paths['foo/bar'] . '" /></a>',
        $this->runFilter('<a href="foo"><img src="foo/bar" /></a>'),
        "Simple paths. Clean URLs: $clean protocol style $protocol_style.",
      );
      $this->assertSame(
        '<a href="' . $paths['foo'] . '"></a><a href="' . $paths['foo/bar?baz=qux'] . '"></a>',
        $this->runFilter('<a href="index.php?q=foo"></a><a href="index.php?q=foo/bar&baz=qux"></a>'),
        "D7 and earlier-style non-clean URLs. Clean URLs: $clean; protocol style: $protocol_style.",
      );
      $this->assertSame(
        '<a href="' . $paths['foo'] . '"></a><a href="' . $paths['foo/bar?baz=qux'] . '"></a>',
        $this->runFilter('<a href="index.php/foo"></a><a href="index.php/foo/bar?baz=qux"></a>'),
        "D8-style non-clean URLs. Clean URLs: $clean; protocol style: $protocol_style.",
      );
      $this->assertSame(
        '<form action="' . $paths['foo/bar?baz'] . '"><IMG LONGDESC="' . $paths['foo/bar?baz=qux'] . '" /></a>',
        $this->runFilter('<form action="foo/bar?baz"><IMG LONGDESC="foo/bar?baz=qux" /></a>'),
        "Paths with query string. Clean URLs: $clean; protocol style: $protocol_style.",
      );
      $this->assertSame(
        '<a href="' . $paths['foo/bar#baz'] . '">',
        $this->runFilter('<a href="foo/bar#baz">'),
        "Path with fragment. Clean URLs: $clean; protocol style: $protocol_style.",
      );
      $this->assertSame(
        '<a href="#foo">',
        $this->runFilter('<a href="#foo">'),
        "Fragment-only href. Clean URLs: $clean; protocol style: $protocol_style.",
      );
      $this->assertSame(
        '<source srcset="' . $paths['foo'] . ' 1x, ' . $paths['foo/bar'] . ' 2x" />',
        $this->runFilter('<source srcset="foo 1x, foo/bar 2x" />'),
        "srcset attribute with pixel density descriptors. Clean URLs: $clean protocol style $protocol_style.",
      );
      $this->assertSame(
        '<img srcset="' . $paths['foo'] . ' 800w, ' . $paths['foo/bar'] . ' 1200w" />',
        $this->runFilter('<img srcset="foo 800w, foo/bar 1200w" />'),
        "srcset attribute with width descriptors. Clean URLs: $clean protocol style $protocol_style.",
      );
      $this->assertSame(
        '<source srcset="' . $paths['foo'] . ' 800w, ' . $paths['foo/bar'] . '" />',
        $this->runFilter('<source srcset="  foo  800w  , foo/bar" />'),
        "srcset attribute with extra spaces and missing descriptor. Clean URLs: $clean protocol style $protocol_style.",
      );
      $this->assertSame(
        '<source srcset="' . $paths['foo/bar?baz=qux&amp;quux=quuux#quuuux'] . '" />',
        $this->runFilter('<source srcset="foo/bar?baz=qux&amp;quux=quuux#quuuux" />'),
        "srcset attribute with query string and fragment. Clean URLs: $clean protocol style $protocol_style.",
      );
      $this->assertSame(
        '<source srcset="' . $paths['foo'] . ' 800w, ' . $paths['foo/bar'] . '" />',
        $this->runFilter('<source srcset="foo  800w , foo/bar" />'),
        "srcset attribute escaped entities. Clean URLs: $clean protocol style $protocol_style.",
      );
      // @see https://drupal.org/node/2208223
      $this->assertSame(
        '<a href="#">',
        $this->runFilter('<a href="#">'),
        "Hash-only href. Clean URLs: $clean; protocol style: $protocol_style.",
      );
      $this->assertSame(
        '<a href="' . $paths['foo/bar?baz=qux&amp;quux=quuux#quuuux'] . '">',
        $this->runFilter('<a href="foo/bar?baz=qux&amp;quux=quuux#quuuux">'),
        "Path with query string and fragment. Clean URLs: $clean; protocol style: $protocol_style.",
      );
      $this->assertSame(
        '<a href="' . $paths['foo%20bar?baz=qux%26quux'] . '">',
        $this->runFilter('<a href="foo%20bar?baz=qux%26quux">'),
        "Path with URL encoded parts. Clean URLs: $clean; protocol style: $protocol_style.",
      );
      $this->assertSame(
        '<a href="' . $paths['/'] . '"></a>',
        $this->runFilter('<a href="/"></a>'),
        "Path with just slash. Clean URLs: $clean; protocol style: $protocol_style.",
      );
    }

    global $base_path;
    $this->assertSame(
      '<a href="' . $this->pathologicContentUrl('foo', ['absolute' => FALSE]) . '">bar</a>',
      $this->runFilter('<a href="' . $base_path . 'foo">bar</a>'),
      'Paths beginning with base_path (like WYSIWYG editors like to make)'
    );

    global $base_url;
    $this->assertSame(
      '<a href="' . $this->pathologicContentUrl('foo', ['absolute' => FALSE]) . '">bar</a>',
      $this->runFilter('<a href="' . $base_url . '/foo">bar</a>'),
      "Paths beginning with $base_url using $this->formatId"
    );

    // @see http://drupal.org/node/1617944
    $this->assertSame(
      '<a href="//example.com/foo">bar</a>',
      $this->runFilter('<a href="//example.com/foo">bar</a>'),
      'Off-site schemeless URLs (//example.com/foo) ignored'
    );

    // Test internal: and all base paths.
    $this->buildFormat([
      'settings_source' => 'local',
      'local_settings' => [
        'local_paths' => "http://example.com/qux\nhttp://example.org\n/bananas",
        'protocol_style' => 'full',
      ],
    ]);

    // @see https://drupal.org/node/2030789
    $this->assertSame(
      '<a href="' . $this->pathologicContentUrl('foo', ['absolute' => TRUE]) . '">bar</a>',
      $this->runFilter('<a href="//example.org/foo">bar</a>'),
      'On-site schemeless URLs processed'
    );
    $this->assertSame(
      '<a href="' . $this->pathologicContentUrl('foo', ['absolute' => TRUE]) . '">',
      $this->runFilter('<a href="internal:foo">'),
      'Path Filter compatibility (internal:)'
    );
    $this->assertSame(
      '<a href="' . $this->pathologicContentUrl(\Drupal::service('file_url_generator')->generateAbsoluteString(\Drupal::config('system.file')->get('default_scheme') . '://image.jpeg'), []) . '">look</a>',
      urldecode($this->runFilter('<a href="files:image.jpeg">look</a>')),
      'Path Filter compatibility (files:)'
    );
    $this->assertSame(
      '<a href="' . $this->pathologicContentUrl('foo', ['absolute' => TRUE]) . '"><picture><source srcset="' . $this->pathologicContentUrl('bar.jpeg', ['absolute' => TRUE]) . '" /><img src="' . $this->pathologicContentUrl('bar.jpeg', ['absolute' => TRUE]) . '" longdesc="' . $this->pathologicContentUrl('baz', ['absolute' => TRUE]) . '" /></picture></a>',
      $this->runFilter('<a href="http://example.com/qux/foo"><picture><source srcset="http://example.org/bar.jpeg" /><img src="http://example.org/bar.jpeg" longdesc="/bananas/baz" /></picture></a>'),
      '"All base paths for this site" functionality'
    );
    $this->assertSame(
      '<a href="webcal:foo">bar</a>',
      $this->runFilter('<a href="webcal:foo">bar</a>'),
      'URLs with likely protocols are ignored'
    );
    // Test hook_pathologic_alter() implementation.
    $this->assertSame(
      '<a href="' . $this->pathologicContentUrl('foo', [
        'absolute' => TRUE,
        'query' => [
          'test' => 'add_foo_qpart',
          'foo' => 'bar',
        ],
      ]) . '">',
      $this->runFilter('<a href="foo?test=add_foo_qpart">'),
      'hook_pathologic_alter(): Alter $url_params'
    );
    $this->assertSame(
      '<a href="bar?test=use_original">',
      $this->runFilter('<a href="bar?test=use_original">'),
      'hook_pathologic_alter(): Passthrough with use_original option'
    );
    $this->assertSame(
      '<a href="http://cdn.example.com/bar?test=external">',
      $this->runFilter('<a href="bar?test=external">'),
    );

    // Test paths to existing files when clean URLs are disabled.
    // @see http://drupal.org/node/1672430
    $_pathologic_script_path = '';
    $filtered_tag = $this->runFilter('<img src="misc/druplicon.png" />');
    $this->assertTrue(
      strpos($filtered_tag, 'q=') === FALSE,
      "Paths to files don't have ?q= when clean URLs are off"
    );

    $this->buildFormat([
      'settings_source' => 'global',
      'local_settings' => [
        'protocol_style' => 'rel',
      ],
    ]);
    $this->config('pathologic.settings')
      ->set('protocol_style', 'proto-rel')
      ->set('local_paths', 'http://example.com/')
      ->save();
    $this->assertSame(
      '<img src="' . _pathologic_url_to_protocol_relative($this->pathologicContentUrl('foo.jpeg', ['absolute' => TRUE])) . '" />',
      $this->runFilter('<img src="http://example.com/foo.jpeg" />'),
      'Use global settings when so configured on the format'
    );

    // Test really broken URLs.
    // @see https://www.drupal.org/node/2602312
    $original = '<a href="/Epic:failure">foo</a>';
    try {
      $this->runFilter($original);
    }
    catch (\Exception) {
      $this->fail('Fails miserably when \Drupal\Core\Url::fromUri() throws exception');
    }

  }

}
