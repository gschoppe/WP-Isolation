# WP Isolation
by [Greg Schoppe](https://gschoppe.com)

**PROOF OF CONCEPT - USE AT YOUR OWN RISK**

Isolate one or more WordPress post types to a set of dedicated database tables (a sandbox, if you will), with their own separate set of IDs. Why? Maybe just to punish myself. You tell me.

## Usage

### Setup:
Install and activate the plugin, then after your post types and taxonomies are declared, call WP Isolate like this:

``` php
$sandbox_name = WPIsolate::Init( array( 'post_type_slugs' ), array( 'taxonomy_slugs' ), 'sandbox_name' );
```

Where `sandbox_name` is an optional slug to identify the sandboxed tables. If you omit `'sandbox_name'`, your sandbox will automatically be assigned a name.

the `WPIsolate::Init()` function is shorthand for:

``` php
$wpi = WPIsolate::Instance();
$sandbox = $wpi->register( array( 'post_type_slugs' ), array( 'taxonomy_slugs' ) );
```

### Shortcode:

You may need to run a shortcode that references posts from another sandbox. to do so, simply wrap that shortcode in a `[sandbox]` shortcode, like so:

```
[sandbox name='example']
	[some-other-shortcode]
	some random content
	[yet-another-third-party-shortcode]
[/sandbox]
```

The optional `name` attribute lets you select a specific sandbox. without that attribute, the  shortcode defaults to returning to the main sandbox from whatever sandbox is currently running.

### Helper Functions:

These helpers are a set of two functions that can surround a set of operations, forcing them to take place in the context of a specific sandbox, similar to how the shortcode wraps content.

``` php
$wpi = WPIsolate::Instance();
$wpi->start_temp_sandbox( 'sandbox_name' );
// insert your code for sandbox "sandbox_name" here
$wpi->end_temp_sandbox();

```

## Unsupported

### Multisites

Theoretically, there is no reason this code couldn't work on a multisite, with enough tweaking, but it hasn't been considered for the proof of concept stage, so multisite tables are not auto-generated.

### Featured Images

Currently, Featured Images and Media are completely unsupported for sandboxed post types. They could be supported in the future, but media library support adds a whole new can of worms about where to store media that relates specifically to a sandboxed post... the likely answer is "in the same sandbox", but that starts to raise issues of split post types. Whoever decided that media was a post_type that has unique relationships to other post types was not thinking abstractly. The same can be said for Menu items.

### Taxonomies attached to multiple post types in different sandboxes

Just no. Isolation means isolation.

### Gutenberg

Since Gutenberg relies on the WP API to communicate with the database, an I haven't touched that yet, this is not Gutenberg compatible at the moment. It seems doubtful that WP API will be flexible enough to support the necessary hacks to make this work.

### Pretty much any sufficiently complicated plugin

This is a proof of concept, designed to be used on custom post types, where everything is manually set by the developer. Trying to sandbox a page builder or WooCommerce or things like that is just not gonna work. I'm not even certain it will support ACF, although it's worth a shot. Yoast, on the other hand, works great!

### Revisions

These could certainly be made to work, but the effort hasn't been put in for proof of concept code
