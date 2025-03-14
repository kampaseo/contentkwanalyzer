<?php
/*
Plugin Name: Sitemap Parser
Plugin URI: 
Description: Sitemap URL'lerini parse edip dropdown menüde gösterir
Version: 1.0
Author: Your Name
*/

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit;
}

// Composer autoload
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Eklenti aktivasyonunda çalışacak fonksiyon
register_activation_hook(__FILE__, 'sitemap_parser_activate');
function sitemap_parser_activate() {
    global $wpdb;
    
    // Mevcut ayarlar
    add_option('gsc_client_id', '');
    add_option('gsc_client_secret', '');
    add_option('gsc_site_url', '');
    add_option('gsc_access_token', '');

    // Yeni tablo oluştur
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'page_keyword_analysis';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        url varchar(2083) NOT NULL,
        total_keywords int(11) NOT NULL,
        found_keywords int(11) NOT NULL,
        scan_date datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY url (url(191)),
        KEY scan_date (scan_date)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Admin init hook'una GSC ayarlarını ekle
add_action('admin_init', 'sitemap_parser_settings_init');
function sitemap_parser_settings_init() {
    register_setting('sitemap_parser', 'gsc_client_id');
    register_setting('sitemap_parser', 'gsc_client_secret');
    register_setting('sitemap_parser', 'gsc_site_url');
    register_setting('sitemap_parser', 'gsc_access_token');
}

// Admin menüsüne eklenti sayfasını ekle
add_action('admin_menu', 'sitemap_parser_menu');
function sitemap_parser_menu() {
    add_menu_page(
        'Sitemap Parser',
        'Sitemap Parser',
        'manage_options',
        'sitemap-parser',
        'sitemap_parser_page',
        'dashicons-networking'
    );
    
    // Alt menüler
    add_submenu_page(
        'sitemap-parser',
        'Bulk Site Tarama',
        'Bulk Tarama',
        'manage_options',
        'sitemap-parser-bulk',
        'sitemap_parser_bulk_page'
    );
    
    add_submenu_page(
        'sitemap-parser',
        'Search Console Ayarları',
        'Ayarlar',
        'manage_options',
        'sitemap-parser-settings',
        'sitemap_parser_settings_page'
    );
}

// OAuth callback işleyicisini admin_init hook'una ekle
add_action('admin_init', 'handle_oauth_callback');
function handle_oauth_callback() {
    // Sadece settings sayfasındayken çalış
    if (!isset($_GET['page']) || $_GET['page'] !== 'sitemap-parser-settings') {
        return;
    }

    // OAuth callback kontrolü
    if (isset($_GET['code'])) {
        $client = get_google_client();
        try {
            $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
            if (!isset($token['error'])) {
                update_option('gsc_access_token', $token);
                wp_redirect(remove_query_arg('code'));
                exit;
            }
        } catch (Exception $e) {
            add_settings_error('sitemap_parser', 'oauth_error', 'Google hesabı bağlantısında hata: ' . $e->getMessage());
        }
    }

    // Bağlantıyı kesme işlemi
    if (isset($_GET['disconnect'])) {
        delete_option('gsc_access_token');
        delete_option('gsc_site_url');
        wp_redirect(remove_query_arg('disconnect'));
        exit;
    }
}

// Ayarlar sayfası
function sitemap_parser_settings_page() {
    // Debug için tam URI'yi göster - site_url() kullanarak
    $redirect_uri = site_url('wp-admin/admin.php?page=sitemap-parser-settings');
    echo '<div class="notice notice-info">';
    echo '<p><strong>Tam Redirect URI:</strong> <code>' . esc_html($redirect_uri) . '</code></p>';
    echo '<p>Bu URI\'yi Google Cloud Console\'da "Authorized redirect URIs" alanına eksiksiz kopyalayın.</p>';
    echo '<p>Not: URI\'nin sonunda boşluk veya fazladan karakter olmadığından emin olun.</p>';
    echo '</div>';

    // Site seçimi yapıldığında
    if (isset($_POST['gsc_selected_site'])) {
        update_option('gsc_site_url', sanitize_text_field($_POST['gsc_selected_site']));
        add_settings_error('sitemap_parser', 'site_updated', 'Search Console site seçimi güncellendi.', 'success');
    }
    ?>
    <div class="wrap">
        <h1>Search Console Ayarları</h1>
        
        <?php settings_errors(); ?>

        <form method="post" action="options.php">
            <?php settings_fields('sitemap_parser'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Client ID</th>
                    <td>
                        <input type="text" name="gsc_client_id" value="<?php echo esc_attr(get_option('gsc_client_id')); ?>" class="regular-text">
                        <p class="description">Google Cloud Console'dan aldığınız OAuth2 Client ID'yi girin</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Client Secret</th>
                    <td>
                        <input type="password" name="gsc_client_secret" value="<?php echo esc_attr(get_option('gsc_client_secret')); ?>" class="regular-text">
                        <p class="description">Google Cloud Console'dan aldığınız OAuth2 Client Secret'ı girin</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Ayarları Kaydet'); ?>
        </form>

        <?php
        // Yetkilendirme durumunu kontrol et
        $client = get_google_client();
        if (!$client->getAccessToken()) {
            $auth_url = $client->createAuthUrl();
            echo '<div class="notice notice-warning"><p>Google Search Console ile bağlantı kurulmamış. ';
            echo '<a href="' . esc_url($auth_url) . '" class="button button-primary">Google ile Bağlan</a></p></div>';
        } else {
            try {
                // Search Console servisini başlat
                $service = new Google_Service_SearchConsole($client);
                
                // Kullanıcının tüm Search Console sitelerini al
                $sites = $service->sites->listSites()->getSiteEntry();
                
                echo '<div class="notice notice-success"><p>Google Search Console ile bağlantı kuruldu. ';
                echo '<a href="' . esc_url(add_query_arg('disconnect', '1')) . '" class="button">Bağlantıyı Kes</a></p></div>';
                
                // Site seçim formu
                $selected_site = get_option('gsc_site_url');
                ?>
                <h2>Search Console Site Seçimi</h2>
                <form method="post" action="">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Site Seçin</th>
                            <td>
                                <select name="gsc_selected_site" class="regular-text">
                                    <option value="">Site seçin...</option>
                                    <?php foreach ($sites as $site) : ?>
                                        <option value="<?php echo esc_attr($site->getSiteUrl()); ?>" 
                                                <?php selected($selected_site, $site->getSiteUrl()); ?>>
                                            <?php echo esc_html($site->getSiteUrl()); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Site Seçimini Kaydet'); ?>
                </form>
                <?php
            } catch (Exception $e) {
                echo '<div class="notice notice-error"><p>Search Console siteleri alınırken hata oluştu: ' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        ?>
    </div>
    <?php
}

// Ana sayfa fonksiyonunu güncelle
function sitemap_parser_page() {
    ?>
    <style>
        .filter-controls {
            display: flex;
            gap: 20px;
            align-items: center;
            margin: 15px 0;
            padding: 15px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            max-width: 1200px; /* Maksimum genişlik ekle */
        }
        
        .results-layout {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 20px;
            max-width: 1200px; /* Filtre kontrolleriyle aynı genişlik */
        }
        
        .url-selector {
            width: 100%;
            padding: 20px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: flex;
            flex-direction: column;
        }
        
        .url-list {
            flex: 1;
            overflow-y: auto;
            margin: 10px 0;
            border: 1px solid #eee;
            border-radius: 4px;
            max-height: 600px;
        }
        
        .url-item {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            background: #fff;
        }
        .url-item:hover {
            background: #f8f9fa;
        }
        .url-item.active {
            background: #e8f5e9;
        }
        .url-text {
            flex: 1;
            min-width: 200px;
            word-break: break-all; /* Uzun URL'leri kır */
        }
        .url-stats {
            font-size: 12px;
            color: #666;
            margin-left: 20px;
            white-space: nowrap; /* İstatistikleri tek satırda tut */
        }
        .url-filter {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            position: sticky;
            top: 0;
            background: #fff;
        }
        .url-stats-summary {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 10px;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .url-stats-summary p {
            margin: 0;
            font-size: 13px;
            color: #666;
            white-space: normal; /* İstatistik özetini gerektiğinde birden fazla satıra böl */
        }

        /* Scrollbar stilleri */
        .url-list::-webkit-scrollbar {
            width: 8px;
        }
        .url-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        .url-list::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        .url-list::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        .keyword-density {
            font-size: 12px;
            color: #666;
            margin-left: 8px;
        }
        .keyword-analysis {
            flex: 1;
        }
        .keyword-table tr.found {
            background-color: #f0fff0;
        }
        .keyword-table tr.not-found {
            background-color: #fff0f0;
        }
        .status-icon {
            font-size: 18px;
            font-weight: bold;
        }
        .status-icon.found {
            color: #46b450;
        }
        .status-icon.not-found {
            color: #dc3232;
        }
        .loading {
            display: none;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .loading.active {
            display: block;
        }
        .page-stats {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        .url-stats-summary {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        .url-stats-summary p {
            margin: 0;
            font-size: 13px;
            color: #666;
        }
        .url-list {
            max-height: 500px;
        }
        .url-item {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .url-text {
            flex: 1;
            min-width: 200px;
        }
        .url-stats {
            font-size: 12px;
            color: #666;
            margin-left: 20px;
        }
        .date-range-selector {
            margin-bottom: 15px;
            padding: 10px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .date-range-selector select {
            padding: 5px;
            border-radius: 4px;
        }
        .sorting-options {
            margin: 15px 0;
            padding: 10px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .sorting-options select {
            padding: 5px;
            border-radius: 4px;
        }
        .sort-direction {
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 4px;
            border: 1px solid #ddd;
            background: #f8f9fa;
        }
        .sort-direction.active {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
        }
    </style>

    <div class="wrap">
        <h1>Sayfa Analizi</h1>
        
        <?php
        // Search Console bağlantı kontrolü ve veri alma
        $client = get_google_client();
        if (!$client->getAccessToken()) {
            echo '<div class="notice notice-error"><p>Google Search Console ile bağlantı kurulmamış. ';
            echo '<a href="' . esc_url(admin_url('admin.php?page=sitemap-parser-settings')) . '">Ayarlar sayfasından</a> bağlantıyı yapılandırın.</p></div>';
            return;
        }

        // Parametreleri al
        $selected_range = isset($_GET['date_range']) ? $_GET['date_range'] : '30';
        $sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'clicks';
        $sort_dir = isset($_GET['dir']) ? $_GET['dir'] : 'desc';
        
        // Search Console'dan verileri al
        $gsc_data = array();
        $pages = get_top_pages(10000, $selected_range);//Sos-1000
        
        // URL kategorileri için sayaçlar
        $url_stats = array(
            'total' => 0,
            'meta' => 0,
            'urun' => 0,
            'kategori' => 0,
            'marka' => 0,
            'sayfa' => 0,
            'diger' => 0
        );

        if (!is_wp_error($pages)) {
            foreach ($pages as $page) {
                $url = $page->getKeys()[0];
                $gsc_data[$url] = array(
                    'clicks' => $page->getClicks(),
                    'impressions' => $page->getImpressions(),
                    'ctr' => round(($page->getClicks() / $page->getImpressions()) * 100, 2),
                    'position' => round($page->getPosition(), 1)
                );

                // URL tipini belirle ve sayaçları güncelle
                $url_stats['total']++;
                if (strpos($url, '/meta/') !== false) {
                    $url_stats['meta']++;
                } elseif (strpos($url, '/urun/') !== false) {
                    $url_stats['urun']++;
                } elseif (strpos($url, '/kategori/') !== false) {
                    $url_stats['kategori']++;
                } elseif (strpos($url, '/marka/') !== false) {
                    $url_stats['marka']++;
                } elseif (strpos($url, '/sayfa/') !== false) {
                    $url_stats['sayfa']++;
                } else {
                    $url_stats['diger']++;
                }
            }

            // Sıralama işlemi
            uasort($gsc_data, function($a, $b) use ($sort_by, $sort_dir) {
                if ($sort_by === 'url') {
                    return $sort_dir === 'asc' ? 
                        strcmp(array_search($a, $gsc_data), array_search($b, $gsc_data)) :
                        strcmp(array_search($b, $gsc_data), array_search($a, $gsc_data));
                }
                
                if ($a[$sort_by] == $b[$sort_by]) return 0;
                
                if ($sort_dir === 'asc') {
                    return $a[$sort_by] > $b[$sort_by] ? 1 : -1;
                } else {
                    return $a[$sort_by] < $b[$sort_by] ? 1 : -1;
                }
            });
        }

        // Tarih aralığı ve sıralama seçenekleri
        $date_ranges = array(
            '7' => 'Son 7 gün',
            '30' => 'Son 30 gün',
            '90' => 'Son 90 gün',
            '180' => 'Son 180 gün',
            '365' => 'Son 1 yıl',
            'all' => 'Tüm zamanlar (16 ay)'
        );

        $sort_options = array(
            'clicks' => 'Tıklama',
            'impressions' => 'Gösterim',
            'ctr' => 'CTR',
            'position' => 'Pozisyon',
            'url' => 'URL'
        );
        ?>

        <div class="filter-controls">
            <div class="filter-group">
                <label for="date-range">İstatistik Aralığı:</label>
                <select id="date-range" name="date_range">
                    <?php foreach ($date_ranges as $days => $label) : ?>
                        <option value="<?php echo $days; ?>" <?php selected($selected_range, $days); ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-separator"></div>

            <div class="sort-controls">
                <label for="sort-by">Sıralama:</label>
                <select id="sort-by" name="sort">
                    <?php foreach ($sort_options as $value => $label) : ?>
                        <option value="<?php echo $value; ?>" <?php selected($sort_by, $value); ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <div class="sort-buttons">
                    <button type="button" class="sort-direction <?php echo $sort_dir === 'asc' ? 'active' : ''; ?>" 
                            data-dir="asc" title="Artan">
                        ↑ Artan
                    </button>
                    <button type="button" class="sort-direction <?php echo $sort_dir === 'desc' ? 'active' : ''; ?>" 
                            data-dir="desc" title="Azalan">
                        ↓ Azalan
                    </button>
                </div>
            </div>
        </div>

        <div class="results-layout">
            <div class="url-selector">
                <h3>Site URL'leri</h3>
                <div class="url-stats-summary">
                    <p>
                        Toplam URL: <?php echo $url_stats['total']; ?> |
                        Meta: <?php echo $url_stats['meta']; ?> |
                        Ürün: <?php echo $url_stats['urun']; ?> |
                        Kategori: <?php echo $url_stats['kategori']; ?> |
                        Marka: <?php echo $url_stats['marka']; ?> |
                        Sayfa: <?php echo $url_stats['sayfa']; ?> |
                        Diğer: <?php echo $url_stats['diger']; ?>
                    </p>
                </div>
                <input type="text" class="url-filter" placeholder="URL'leri filtrele..." id="url-filter">
                <div class="url-list">
                    <?php
                    // URL listesini göster
                    foreach ($gsc_data as $url => $metrics) : ?>
                        <div class="url-item" data-url="<?php echo esc_attr($url); ?>">
                            <div class="url-text"><?php echo esc_html($url); ?></div>
                            <div class="url-stats">
                                Tıklama: <?php echo $metrics['clicks']; ?> | 
                                Gösterim: <?php echo $metrics['impressions']; ?> | 
                                CTR: <?php echo $metrics['ctr']; ?>% | 
                                Poz: <?php echo $metrics['position']; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Anahtar Kelime Analizi -->
            <div id="loading" class="loading">
                Anahtar kelimeler analiz ediliyor...
            </div>
            <div id="keyword-results"></div>
        </div>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // URL filtreleme - geliştirilmiş versiyon
        $('#url-filter').on('keyup', function() {
            var searchText = $(this).val().toLowerCase().trim();
            
            $('.url-item').each(function() {
                var $item = $(this);
                var urlText = $item.find('.url-text').text().toLowerCase();
                var statsText = $item.find('.url-stats').text().toLowerCase();
                
                // URL veya istatistiklerde eşleşme ara
                if (urlText.indexOf(searchText) > -1 || statsText.indexOf(searchText) > -1) {
                    $item.show();
                } else {
                    $item.hide();
                }
            });
        });

        // URL seçimi
        $('.url-item').on('click', function() {
            $('.url-item').removeClass('active');
            $(this).addClass('active');
            analyzeUrl($(this).data('url'));
        });

        // Tarih aralığı değiştiğinde
        $('#date-range').on('change', function() {
            var dateRange = $(this).val();
            var loading = $('#loading');
            loading.addClass('active').html('Veriler güncelleniyor...');

            // Mevcut URL'yi al ve date_range parametresini güncelle
            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('date_range', dateRange);
            
            // Sayfayı yeni URL ile yenile
            window.location.href = currentUrl.toString();
        });

        // Sıralama yönü butonları
        $('.sort-direction').on('click', function() {
            var dir = $(this).data('dir');
            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('dir', dir);
            window.location.href = currentUrl.toString();
        });

        // Sıralama seçeneği değiştiğinde
        $('#sort-by').on('change', function() {
            var sort = $(this).val();
            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('sort', sort);
            window.location.href = currentUrl.toString();
        });
    });

    function analyzeUrl(url) {
        if (!url) return;

        const loading = document.getElementById('loading');
        const results = document.getElementById('keyword-results');
        
        loading.classList.add('active');
        results.innerHTML = '';

        // AJAX isteği
        jQuery.post(ajaxurl, {
            action: 'analyze_url_keywords',
            url: url,
            _ajax_nonce: '<?php echo wp_create_nonce("analyze_url_keywords"); ?>'
        }, function(response) {
            loading.classList.remove('active');
            if (response.success) {
                results.innerHTML = response.data;
            } else {
                results.innerHTML = '<div class="notice notice-error"><p>' + response.data + '</p></div>';
            }
        });
    }
    </script>
    <?php
}

// Sitemap'i parse eden fonksiyon
function parse_sitemap($sitemap_url, $processed_urls = array()) {
    // SSL sertifika doğrulamasını devre dışı bırak
    $args = array(
        'sslverify' => false,
        'timeout' => 30,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    );

    $response = wp_remote_get($sitemap_url, $args);
    
    if (is_wp_error($response)) {
        return new WP_Error('fetch_error', 'Sitemap alınamadı: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    
    if (empty($body)) {
        return new WP_Error('empty_response', 'Boş yanıt alındı. URL: ' . $sitemap_url);
    }

    $urls = array();

    // XML'i parse et
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);

    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        return new WP_Error('parse_error', 'XML parse hatası: ' . $errors[0]->message);
    }

    // Debug bilgisi
    error_log('Processing sitemap: ' . $sitemap_url);

    // Sitemap index format
    if ($xml->sitemap) {
        foreach ($xml->sitemap as $sitemap) {
            $sub_sitemap_url = (string)$sitemap->loc;
            if (!in_array($sub_sitemap_url, $processed_urls)) {
                $processed_urls[] = $sub_sitemap_url;
                $sub_urls = parse_sitemap($sub_sitemap_url, $processed_urls);
                if (!is_wp_error($sub_urls)) {
                    $urls = array_merge($urls, $sub_urls);
                }
            }
        }
    }
    
    // Standard sitemap format
    if ($xml->url) {
        foreach ($xml->url as $url) {
            $urls[] = (string)$url->loc;
        }
    }

    // URL'leri benzersiz yap
    $urls = array_unique($urls);

    return $urls;
}

function categorize_urls($urls) {
    $categories = array(
        'meta' => array('count' => 0, 'urls' => array()),
        'urun' => array('count' => 0, 'urls' => array()),
        'kategori' => array('count' => 0, 'urls' => array()),
        'marka' => array('count' => 0, 'urls' => array()),
        'sayfa' => array('count' => 0, 'urls' => array()),
        'diger' => array('count' => 0, 'urls' => array())
    );

    foreach ($urls as $url) {
        $url_lower = strtolower($url);
        
        if (strpos($url_lower, 'meta') !== false) {
            $categories['meta']['urls'][] = $url;
            $categories['meta']['count']++;
        } elseif (strpos($url_lower, 'urun') !== false) {
            $categories['urun']['urls'][] = $url;
            $categories['urun']['count']++;
        } elseif (strpos($url_lower, 'kategori') !== false) {
            $categories['kategori']['urls'][] = $url;
            $categories['kategori']['count']++;
        } elseif (strpos($url_lower, 'marka') !== false) {
            $categories['marka']['urls'][] = $url;
            $categories['marka']['count']++;
        } elseif (strpos($url_lower, 'sayfa') !== false) {
            $categories['sayfa']['urls'][] = $url;
            $categories['sayfa']['count']++;
        } else {
            $categories['diger']['urls'][] = $url;
            $categories['diger']['count']++;
        }
    }

    return $categories;
}

// Google Client fonksiyonunu güncelle
function get_google_client() {
    $client = new Google_Client();
    $client->setApplicationName('Sitemap Parser');
    $client->setScopes(['https://www.googleapis.com/auth/webmasters.readonly']);
    
    // Client ID ve Client Secret kullan
    $client->setClientId(get_option('gsc_client_id'));
    $client->setClientSecret(get_option('gsc_client_secret'));
    
    // Tam redirect URI'yi site_url() ile oluştur
    $redirect_uri = site_url('wp-admin/admin.php?page=sitemap-parser-settings');
    $client->setRedirectUri($redirect_uri);

    // Access token kontrolü
    $access_token = get_option('gsc_access_token');
    if (!empty($access_token)) {
        $client->setAccessToken($access_token);

        // Token süresi dolmuşsa yenile
        if ($client->isAccessTokenExpired()) {
            $refresh_token = $client->getRefreshToken();
            if ($refresh_token) {
                try {
                    $client->fetchAccessTokenWithRefreshToken($refresh_token);
                    update_option('gsc_access_token', $client->getAccessToken());
                } catch (Exception $e) {
                    delete_option('gsc_access_token');
                    return $client;
                }
            }
        }
    }

    return $client;
}

function get_top_pages($limit = 100, $days = '30') {
    try {
        $client = get_google_client();
        if (!$client->getAccessToken()) {
            return new WP_Error('gsc_error', 'Google Search Console ile bağlantı kurulmamış.');
        }

        $service = new Google_Service_SearchConsole($client);
        $site_url = get_option('gsc_site_url');

        $request = new Google_Service_SearchConsole_SearchAnalyticsQueryRequest();
        
        // Tarih aralığını ayarla
        if ($days === 'all') {
            $request->setStartDate(date('Y-m-d', strtotime('-16 months')));
        } else {
            $request->setStartDate(date('Y-m-d', strtotime("-{$days} days")));
        }
        $request->setEndDate(date('Y-m-d'));
        $request->setDimensions(['page']);
        $request->setRowLimit($limit);
        
        $response = $service->searchanalytics->query($site_url, $request);
        
        // Debug için
        error_log('GSC Response: ' . print_r($response->getRows(), true));
        
        return $response->getRows();
    } catch (Exception $e) {
        error_log('GSC Error: ' . $e->getMessage());
        return new WP_Error('gsc_error', $e->getMessage());
    }
}

function get_page_keywords($url, $limit = 20) {
    try {
        $client = get_google_client();
        if (!$client->getAccessToken()) {
            return new WP_Error('gsc_error', 'Google Search Console ile bağlantı kurulmamış.');
        }

        $service = new Google_Service_SearchConsole($client);
        $site_url = get_option('gsc_site_url');

        $request = new Google_Service_SearchConsole_SearchAnalyticsQueryRequest();
        $request->setStartDate(date('Y-m-d', strtotime('-30 days')));
        $request->setEndDate(date('Y-m-d'));
        $request->setDimensions(['query']);
        $request->setRowLimit($limit);
        $request->setDimensionFilterGroups([
            [
                'filters' => [
                    [
                        'dimension' => 'page',
                        'operator' => 'equals',
                        'expression' => $url
                    ]
                ]
            ]
        ]);
        
        $response = $service->searchanalytics->query($site_url, $request);
        return $response->getRows();
    } catch (Exception $e) {
        return new WP_Error('gsc_error', $e->getMessage());
    }
}

// AJAX handler güncelleme
add_action('wp_ajax_analyze_url_keywords', 'analyze_url_keywords_callback');
function analyze_url_keywords_callback() {
    check_ajax_referer('analyze_url_keywords');
    
    if (!isset($_POST['url'])) {
        wp_send_json_error('URL parametresi eksik');
    }

    $url = esc_url_raw($_POST['url']);
    
    // Sayfa içeriğini gelişmiş ayarlarla al
    $args = array(
        'timeout' => 30,                 // Timeout süresini 30 saniyeye çıkar
        'redirection' => 5,             // Maksimum yönlendirme sayısı
        'sslverify' => false,           // SSL doğrulamasını devre dışı bırak
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'headers' => array(
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ),
    );

    $response = wp_remote_get($url, $args);
    
    if (is_wp_error($response)) {
        wp_send_json_error('Sayfa içeriği alınamadı: ' . $response->get_error_message() . 
                          '. URL: ' . $url);
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        wp_send_json_error('Sayfa yanıt kodu: ' . $status_code . '. URL: ' . $url);
    }

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
        wp_send_json_error('Sayfa içeriği boş geldi. URL: ' . $url);
    }

    // HTML'i temizle ve metin içeriğini hazırla
    $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html));
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    $content = strtolower(trim($html));

    // Debug bilgisi
    error_log('URL içeriği alındı: ' . $url . ' (Boyut: ' . strlen($content) . ' bytes)');

    // Anahtar kelimeleri al
    $keywords = get_page_keywords($url, 20);
    if (is_wp_error($keywords)) {
        wp_send_json_error($keywords->get_error_message());
    }

    // Kelime yoğunluğunu hesapla
    function calculate_keyword_density($content, $keyword) {
        $word_count = str_word_count($content);
        if ($word_count === 0) return 0;
        
        $keyword_count = substr_count(strtolower($content), strtolower($keyword));
        return ($keyword_count / $word_count) * 100;
    }

    ob_start();
    ?>
    <h3>Anahtar Kelime Analizi (İlk 20)</h3>
    <?php if (!empty($content)) : ?>
        <div class="notice notice-success inline">
            <p>✓ Sayfa içeriği başarıyla alındı (<?php echo round(strlen($content) / 1024, 2); ?> KB)</p>
        </div>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped keyword-table">
        <thead>
            <tr>
                <th>Durum</th>
                <th>Anahtar Kelime</th>
                <th>Yoğunluk</th>
                <th>Tıklama</th>
                <th>Gösterim</th>
                <th>CTR</th>
                <th>Ort. Pozisyon</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            if (empty($keywords)) {
                echo '<tr><td colspan="6">Bu sayfa için anahtar kelime verisi bulunamadı.</td></tr>';
            } else {
                foreach ($keywords as $row) {
                    $keyword = strtolower($row->getKeys()[0]);
                    $found = strpos($content, $keyword) !== false;
                    $density = calculate_keyword_density($content, $keyword);
                    ?>
                    <tr class="<?php echo $found ? 'found' : 'not-found'; ?>">
                        <td>
                            <span class="status-icon <?php echo $found ? 'found' : 'not-found'; ?>" 
                                  title="<?php echo $found ? 'Kelime içerikte bulundu' : 'Kelime içerikte bulunamadı'; ?>">
                                <?php echo $found ? '✓' : '✗'; ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($row->getKeys()[0]); ?></td>
                        <td><?php echo number_format($density, 2); ?>%</td>
                        <td><?php echo esc_html($row->getClicks()); ?></td>
                        <td><?php echo esc_html($row->getImpressions()); ?></td>
                        <td><?php echo esc_html(round($row->getCtr() * 100, 2)); ?>%</td>
                        <td><?php echo esc_html(round($row->getPosition(), 1)); ?></td>
                    </tr>
                    <?php
                }
            }
            ?>
        </tbody>
    </table>

    <div class="keyword-summary" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
        <?php
        if (!empty($keywords)) {
            $total_keywords = count($keywords);
            $found_keywords = array_sum(array_map(function($row) use ($content) {
                return strpos($content, strtolower($row->getKeys()[0])) !== false ? 1 : 0;
            }, $keywords));
            
            echo sprintf(
                '<p><strong>Özet:</strong> %d anahtar kelimeden %d tanesi içerikte bulundu (%d%%).</p>',
                $total_keywords,
                $found_keywords,
                round(($found_keywords / $total_keywords) * 100)
            );
        }
        ?>
    </div>
    <?php
    $html = ob_get_clean();
    wp_send_json_success($html);
}

// Bağlantıyı kesme işlemi
if (isset($_GET['gsc_logout'])) {
    delete_option('gsc_access_token');
    wp_redirect(remove_query_arg('gsc_logout'));
    exit;
}

function get_page_content($url) {
    if (!function_exists('curl_init')) {
        return new WP_Error('curl_missing', 'cURL extension is not installed');
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ],
    ]);

    $content = curl_exec($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($error) {
        return new WP_Error('curl_error', $error);
    }

    if ($info['http_code'] !== 200) {
        return new WP_Error('http_error', 'HTTP status code: ' . $info['http_code']);
    }

    return $content;
}

// Bulk tarama sayfası
function sitemap_parser_bulk_page() {
    ?>
    <style>
        .progress-bar {
            width: 100%;
            height: 20px;
            background-color: #f0f0f1;
            border-radius: 3px;
            margin: 10px 0;
        }
        .progress-bar .progress {
            width: 0%;
            height: 100%;
            background-color: #2271b1;
            border-radius: 3px;
            transition: width 0.3s ease-in-out;
        }
        .scan-status {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border-left: 4px solid #2271b1;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .results-table {
            margin-top: 20px;
        }
        .scan-controls {
            margin: 20px 0;
        }
    </style>

    <div class="wrap">
        <h1>Bulk Site Tarama</h1>

        <div class="scan-controls">
            <button id="start-scan" class="button button-primary">Taramayı Başlat</button>
            <button id="stop-scan" class="button" style="display:none;">Taramayı Durdur</button>
        </div>

        <div class="progress-bar">
            <div class="progress"></div>
        </div>

        <div class="scan-status"></div>
        <div class="results-table"></div>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        let isScanning = false;
        let shouldStop = false;
        const startBtn = $('#start-scan');
        const stopBtn = $('#stop-scan');
        const progressBar = $('.progress');
        const statusDiv = $('.scan-status');
        const resultsDiv = $('.results-table');

        startBtn.on('click', function() {
            if (!isScanning) {
                startScan();
            }
        });

        stopBtn.on('click', function() {
            shouldStop = true;
            stopBtn.prop('disabled', true);
            statusDiv.html('Tarama durduruluyor...');
        });

        function startScan() {
            isScanning = true;
            shouldStop = false;
            startBtn.hide();
            stopBtn.show().prop('disabled', false);
            progressBar.width('0%');
            statusDiv.html('Tarama başlatılıyor...');

            // İlk sayfaları al
            $.post(ajaxurl, {
                action: 'get_site_pages',
                _ajax_nonce: '<?php echo wp_create_nonce("bulk_scan"); ?>'
            }, function(response) {
                if (response.success && response.data.pages.length > 0) {
                    processPages(response.data.pages, 0, response.data.pages.length);
                } else {
                    endScan('Sayfa bulunamadı veya bir hata oluştu.');
                }
            });
        }

        function processPages(pages, currentIndex, totalPages) {
            if (shouldStop || currentIndex >= totalPages) {
                endScan('Tarama tamamlandı.');
                return;
            }

            const progress = Math.round((currentIndex / totalPages) * 100);
            progressBar.width(progress + '%');
            statusDiv.html(`Taranan: ${currentIndex}/${totalPages} (${progress}%)`);

            // Sayfayı analiz et
            $.post(ajaxurl, {
                action: 'analyze_bulk_page',
                url: pages[currentIndex],
                _ajax_nonce: '<?php echo wp_create_nonce("bulk_scan"); ?>'
            }, function(response) {
                if (response.success) {
                    updateResults();
                }
                // Sonraki sayfaya geç
                setTimeout(() => {
                    processPages(pages, currentIndex + 1, totalPages);
                }, 1000); // Rate limiting için 1 saniye bekle
            });
        }

        function endScan(message) {
            isScanning = false;
            startBtn.show();
            stopBtn.hide();
            statusDiv.html(message);
            updateResults();
        }

        function updateResults() {
            $.post(ajaxurl, {
                action: 'get_bulk_scan_results',
                _ajax_nonce: '<?php echo wp_create_nonce("bulk_scan"); ?>'
            }, function(response) {
                if (response.success) {
                    resultsDiv.html(response.data);
                }
            });
        }
    });
    </script>
    <?php
}

// AJAX handlers for bulk scanning
add_action('wp_ajax_get_site_pages', 'get_site_pages_callback');
function get_site_pages_callback() {
    check_ajax_referer('bulk_scan');
    
    $pages = get_top_pages(1000); // Tüm sayfaları al
    if (is_wp_error($pages)) {
        wp_send_json_error('Sayfalar alınamadı: ' . $pages->get_error_message());
    }

    $urls = array_map(function($page) {
        return $page->getKeys()[0];
    }, $pages);

    wp_send_json_success(['pages' => $urls]);
}

add_action('wp_ajax_analyze_bulk_page', 'analyze_bulk_page_callback');
function analyze_bulk_page_callback() {
    global $wpdb;
    check_ajax_referer('bulk_scan');

    if (!isset($_POST['url'])) {
        wp_send_json_error('URL parametresi eksik');
    }

    $url = esc_url_raw($_POST['url']);
    $keywords = get_page_keywords($url, 50);
    
    if (is_wp_error($keywords)) {
        wp_send_json_error($keywords->get_error_message());
        return;
    }

    // Sayfa içeriğini al
    $content = get_page_content($url);
    if (is_wp_error($content)) {
        wp_send_json_error($content->get_error_message());
        return;
    }

    // İçeriği temizle
    $content = strtolower(strip_tags($content));
    
    // Anahtar kelimeleri kontrol et
    $total_keywords = count($keywords);
    $found_keywords = 0;
    
    foreach ($keywords as $row) {
        $keyword = strtolower($row->getKeys()[0]);
        if (strpos($content, $keyword) !== false) {
            $found_keywords++;
        }
    }

    // Veritabanına kaydet
    $table_name = $wpdb->prefix . 'page_keyword_analysis';
    $wpdb->insert(
        $table_name,
        [
            'url' => $url,
            'total_keywords' => $total_keywords,
            'found_keywords' => $found_keywords,
            'scan_date' => current_time('mysql')
        ],
        ['%s', '%d', '%d', '%s']
    );

    wp_send_json_success();
}

add_action('wp_ajax_get_bulk_scan_results', 'get_bulk_scan_results_callback');
function get_bulk_scan_results_callback() {
    global $wpdb;
    check_ajax_referer('bulk_scan');

    $table_name = $wpdb->prefix . 'page_keyword_analysis';
    $results = $wpdb->get_results("
        SELECT * FROM $table_name 
        ORDER BY scan_date DESC 
        LIMIT 50
    ");

    ob_start();
    ?>
    <style>
        /* Ana tablo stilleri */
        .scan-results {
            margin-top: 20px;
        }
        .keyword-details {
            display: none;
            background: #fff;
            padding: 20px;
            margin: 0;
            border: 1px solid #ddd;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .result-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .result-row:hover {
            background-color: #f0f7ff !important;
        }
        .result-row.active {
            background-color: #e8f5e9 !important;
        }
        
        /* Anahtar kelime bulutu stilleri */
        .keyword-cloud {
            padding: 15px;
            text-align: center;
            line-height: 2.2;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .keyword-tag {
            display: inline-block;
            padding: 5px 12px;
            margin: 4px;
            border-radius: 15px;
            font-size: 13px;
            transition: all 0.2s;
            cursor: help;
        }
        .keyword-tag.found {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }
        .keyword-tag.not-found {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ef9a9a;
        }
        .keyword-tag:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        /* Metrik kutuları stilleri */
        .metrics {
            display: flex;
            justify-content: space-around;
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .metric-box {
            text-align: center;
            padding: 10px 15px;
            min-width: 120px;
        }
        .metric-value {
            font-size: 24px;
            font-weight: bold;
            color: #2271b1;
        }
        .metric-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        /* Loading göstergesi */
        .loading-keywords {
            text-align: center;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .loading-keywords .spinner {
            float: none;
            margin: 0 auto 10px;
            display: block;
        }
    </style>

    <div class="wrap scan-results">
        <h2>Site Analiz Sonuçları</h2>
        <table class="wp-list-table widefat fixed striped" id="scan-results-table">
            <thead>
                <tr>
                    <th>URL</th>
                    <th>Toplam Anahtar Kelime</th>
                    <th>Bulunan Kelimeler</th>
                    <th>Oran</th>
                    <th>Tarama Tarihi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row) : 
                    $row_id = 'row-' . md5($row->url);
                ?>
                    <tr class="result-row" id="<?php echo $row_id; ?>" data-url="<?php echo esc_attr($row->url); ?>">
                        <td><?php echo esc_html($row->url); ?></td>
                        <td><?php echo esc_html($row->total_keywords); ?></td>
                        <td><?php echo esc_html($row->found_keywords); ?></td>
                        <td><?php echo round(($row->found_keywords / $row->total_keywords) * 100, 1); ?>%</td>
                        <td><?php echo esc_html($row->scan_date); ?></td>
                    </tr>
                    <tr class="details-row">
                        <td colspan="5" class="keyword-details" id="details-<?php echo $row_id; ?>">
                            <div class="loading-keywords">
                                <span class="spinner is-active"></span>
                                <p>Anahtar kelimeler analiz ediliyor...</p>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script type="text/javascript">
    (function($) {
        let activeRow = null;

        function initializeRows() {
            $('#scan-results-table').on('click', '.result-row', function(e) {
                const $row = $(this);
                const url = $row.data('url');
                const rowId = $row.attr('id');
                const $details = $('#details-' + rowId);
                
                // Diğer açık detayları kapat
                if (activeRow && activeRow !== rowId) {
                    $('#details-' + activeRow).slideUp();
                    $('#' + activeRow).removeClass('active');
                }

                // Bu satırın detaylarını aç/kapat
                if ($row.hasClass('active')) {
                    $details.slideUp();
                    $row.removeClass('active');
                    activeRow = null;
                } else {
                    $row.addClass('active');
                    
                    // Eğer detaylar daha önce yüklenmemişse
                    if ($details.find('.keyword-cloud').length === 0) {
                        $details.slideDown();
                        
                        // Anahtar kelimeleri getir
                        $.post(ajaxurl, {
                            action: 'get_url_keywords',
                            url: url,
                            _ajax_nonce: '<?php echo wp_create_nonce("get_url_keywords"); ?>'
                        })
                        .done(function(response) {
                            if (response.success) {
                                $details.html(response.data).hide().fadeIn();
                            } else {
                                $details.html(
                                    '<div class="notice notice-error"><p>' + 
                                    (response.data || 'Anahtar kelimeler yüklenirken bir hata oluştu.') + 
                                    '</p></div>'
                                );
                            }
                        })
                        .fail(function() {
                            $details.html(
                                '<div class="notice notice-error"><p>Sunucu ile iletişim kurulamadı.</p></div>'
                            );
                        });
                    } else {
                        $details.slideDown();
                    }
                    
                    activeRow = rowId;
                }
            });
        }

        // Sayfa yüklendiğinde ve sonuçlar güncellendiğinde event listener'ları yeniden ekle
        $(document).ready(initializeRows);
        $(document).on('bulk_scan_results_updated', initializeRows);
        
    })(jQuery);
    </script>
    <?php
    $html = ob_get_clean();
    wp_send_json_success($html);
}

// Sonuçlar güncellendiğinde event'i tetikle
add_action('wp_ajax_get_bulk_scan_results', function() {
    do_action('bulk_scan_results_updated');
});

// Yeni AJAX handler ekle
add_action('wp_ajax_get_url_keywords', 'get_url_keywords_callback');
function get_url_keywords_callback() {
    check_ajax_referer('get_url_keywords');
    
    if (!isset($_POST['url'])) {
        wp_send_json_error('URL parametresi eksik');
    }

    $url = esc_url_raw($_POST['url']);
    $keywords = get_page_keywords($url, 50);
    
    if (is_wp_error($keywords)) {
        wp_send_json_error($keywords->get_error_message());
        return;
    }

    // Sayfa içeriğini al
    $content = get_page_content($url);
    if (is_wp_error($content)) {
        wp_send_json_error($content->get_error_message());
        return;
    }

    $content = strtolower(strip_tags($content));

    ob_start();
    ?>
    <style>
        .keyword-cloud {
            padding: 15px;
            text-align: center;
            line-height: 2;
        }
        .keyword-tag {
            display: inline-block;
            padding: 5px 12px;
            margin: 4px;
            border-radius: 15px;
            font-size: 13px;
            transition: all 0.2s;
            cursor: help;
        }
        .keyword-tag.found {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }
        .keyword-tag.not-found {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ef9a9a;
        }
        .keyword-tag:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .keyword-stats {
            margin-top: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .keyword-stats table {
            width: 100%;
            border-collapse: collapse;
        }
        .keyword-stats th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
        }
        .keyword-stats td {
            padding: 10px 12px;
            border-bottom: 1px solid #e9ecef;
        }
        .keyword-stats tr:hover {
            background: #f8f9fa;
        }
        .metrics {
            display: flex;
            justify-content: space-around;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .metric-box {
            text-align: center;
            padding: 10px;
        }
        .metric-value {
            font-size: 24px;
            font-weight: bold;
            color: #2271b1;
        }
        .metric-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
    </style>

    <div class="keyword-cloud">
        <?php foreach ($keywords as $row) : 
            $keyword = strtolower($row->getKeys()[0]);
            $found = strpos($content, $keyword) !== false;
            $title = sprintf(
                'Tıklama: %d\nGösterim: %d\nCTR: %.1f%%\nPozisyon: %.1f',
                $row->getClicks(),
                $row->getImpressions(),
                $row->getCtr() * 100,
                $row->getPosition()
            );
        ?>
            <span class="keyword-tag <?php echo $found ? 'found' : 'not-found'; ?>" 
                  title="<?php echo esc_attr($title); ?>">
                <?php echo esc_html($keyword); ?>
            </span>
        <?php endforeach; ?>
    </div>

    <div class="metrics">
        <?php
        $total_clicks = 0;
        $total_impressions = 0;
        $avg_position = 0;
        $found_count = 0;

        foreach ($keywords as $row) {
            $keyword = strtolower($row->getKeys()[0]);
            $total_clicks += $row->getClicks();
            $total_impressions += $row->getImpressions();
            $avg_position += $row->getPosition();
            if (strpos($content, $keyword) !== false) $found_count++;
        }
        $avg_position = count($keywords) > 0 ? $avg_position / count($keywords) : 0;
        ?>
        <div class="metric-box">
            <div class="metric-value"><?php echo $total_clicks; ?></div>
            <div class="metric-label">Toplam Tıklama</div>
        </div>
        <div class="metric-box">
            <div class="metric-value"><?php echo $total_impressions; ?></div>
            <div class="metric-label">Toplam Gösterim</div>
        </div>
        <div class="metric-box">
            <div class="metric-value"><?php echo round(($total_clicks / $total_impressions) * 100, 1); ?>%</div>
            <div class="metric-label">Ortalama CTR</div>
        </div>
        <div class="metric-box">
            <div class="metric-value"><?php echo round($avg_position, 1); ?></div>
            <div class="metric-label">Ortalama Pozisyon</div>
        </div>
        <div class="metric-box">
            <div class="metric-value"><?php echo $found_count; ?>/<?php echo count($keywords); ?></div>
            <div class="metric-label">İçerikte Bulunan</div>
        </div>
    </div>

    <div class="keyword-stats">
        <table>
            <thead>
                <tr>
                    <th>Anahtar Kelime</th>
                    <th>Durum</th>
                    <th>Tıklama</th>
                    <th>Gösterim</th>
                    <th>CTR</th>
                    <th>Pozisyon</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keywords as $row) : 
                    $keyword = strtolower($row->getKeys()[0]);
                    $found = strpos($content, $keyword) !== false;
                ?>
                    <tr>
                        <td><?php echo esc_html($row->getKeys()[0]); ?></td>
                        <td>
                            <span class="keyword-tag <?php echo $found ? 'found' : 'not-found'; ?>" style="font-size: 11px;">
                                <?php echo $found ? '✓ Var' : '✗ Yok'; ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($row->getClicks()); ?></td>
                        <td><?php echo esc_html($row->getImpressions()); ?></td>
                        <td><?php echo esc_html(round($row->getCtr() * 100, 2)); ?>%</td>
                        <td><?php echo esc_html(round($row->getPosition(), 1)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $html = ob_get_clean();
    wp_send_json_success($html);
}

// AJAX handler güncelleme
function update_gsc_data_callback() {
    check_ajax_referer('update_gsc_data');
    
    if (!isset($_POST['date_range'])) {
        wp_send_json_error('Tarih aralığı belirtilmedi');
    }

    $date_range = $_POST['date_range']; // 'all' değeri için string olarak al
    if ($date_range !== 'all' && intval($date_range) <= 0) {
        wp_send_json_error('Geçersiz tarih aralığı');
    }

    wp_send_json_success();
} 
