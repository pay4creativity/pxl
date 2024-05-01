# Paragraph blocks

The Paragraph Blocks module allows you to place each value of a multi-value
paragraph field into a different block. And further it allows you to place
paragraph fields from related entities in a similar manner. It does so by
extending both paragraphs with an admin title that is only used in the UI for
layout and extending layout builder by providing the blocks for placement.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/paragraph_blocks).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/paragraph_blocks).


## Table of contents

- Requirements
- Installation
- Configuration
- Maintainers


## Requirements

This module requires the following modules outside of Drupal core:

- [Ctools](https://www.drupal.org/project/ctools)
- [Paragraphs](https://www.drupal.org/project/paragraphs)


## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).


## Configuration

1. Navigate to Administration > Extend and enable the project and its
   dependencies.
2. Navigate to Administration > Configuration > Content authoring >
   Paragraph Blocks Settings and set the maximum number of paragraphs you
   wish to see. Save Configuration.


### Using with Core Layout Builder per entity type

1. Check "`Use Layout Builder`" on the entity referencing the paragraph.
2. Select "`Save`".
3. Select on "`Manage Layout`".
4. Select on "`Add Block`" from somewhere in the layout builder.
5. The blocks will be named `{paragraph_bundle} item {delta} ({label})`.


### Using with Core Layout Builder per entity

1. If you also select "`Allow each content`", you can place blocks based on
   the admin label you give for each paragraph item, rather than using the
   "`Paragraph Item N`" admin labels you see for the per entity type.
2. Then edit the entity itself. Select on the "`Layout`" tab. Now when you
   select "`Add Block`" instead of seeing "`Paragraph Item N`" you will see
   paragraph items with the admin labels provided during editing.


## Maintainers

- Doug Green - [douggreen](https://www.drupal.org/u/douggreen)
- Vaibhav Jain - [vaibhavjain](https://www.drupal.org/u/vaibhavjain)
- Bas Vredeling - [basvredeling](https://www.drupal.org/u/basvredeling)
