<?php
/**
 * Configuração central da aplicação ROJEX.AI.
 * Sprint 003.1 — Identidade oficial do sistema.
 *
 * Este arquivo centraliza nome, versão, caminhos e identidade visual.
 * Não altera banco de dados.
 */

if (!defined('APP_NAME')) {
    define('APP_NAME', 'ROJEX.AI');
}

if (!defined('APP_FULL_NAME')) {
    define('APP_FULL_NAME', 'ROJEX.AI Enterprise');
}

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0 Enterprise');
}

if (!defined('APP_COMPANY')) {
    define('APP_COMPANY', 'ROJEX Tecnologia');
}

if (!defined('APP_DESCRIPTION')) {
    define('APP_DESCRIPTION', 'ERP Jurídico Inteligente');
}

if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', dirname(__DIR__));
}

if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', '/sgl_advocacia');
}

if (!defined('APP_LOGO')) {
    define('APP_LOGO', APP_BASE_URL . '/assets/img/logo_custom.png');
}

if (!defined('APP_FAVICON')) {
    define('APP_FAVICON', APP_BASE_URL . '/favicon.ico');
}
