<?php

/**
 * @defgroup plugins_generic_crossrefReferenceLinking
 */

/**
 * @file index.php
 *
 * Copyright (c) 2013-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_crossrefReferenceLinking
 * @brief Wrapper for Crossref Reference Linking plugin.
 *
 */
require_once('CrossrefReferenceLinkingPlugin.inc.php');
return new CrossrefReferenceLinkingPlugin();

