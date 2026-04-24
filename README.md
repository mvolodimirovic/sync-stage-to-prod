# Sync Stage to Prod

A custom WordPress plugin for synchronizing content, media, taxonomies, options, and ACF relationships between staging/development and production environments via REST API.

## Overview

**Sync Stage to Prod** is designed for controlled content migration between WordPress environments without replacing the full database.

The plugin synchronizes selected data from one environment to another while preserving relationships between posts, media, taxonomy terms, and ACF fields.

## Key Features

- Synchronization via custom REST API endpoints
- Post type synchronization
- Taxonomy and term synchronization
- Options synchronization
- Media synchronization with ID remapping
- Featured image synchronization
- ACF field support
- ACF Relationship, Post Object, and Page Link field remapping
- WooCommerce gallery media support
- Gutenberg image reference detection
- Two-step relationship synchronization
- Batch processing for large datasets
- Safe exclusion of system/private meta fields
- Validation of related object references before updating relationships

## REST API

Base namespace:

```txt
/wp-json/sync-stp/v1
