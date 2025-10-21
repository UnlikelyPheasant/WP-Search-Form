<?php
/**
 * Plugin Name: Search Form
 * Description: Search form that searches Google News RSS feed and displays results as title links and short descriptions.
 * Version: 1.0.0
 * Author: Ash Burks
 * Author URI: https://unlikelypheasant.github.io/Ash.Burks.github.io/
 * Text Domain: search-form
 */

add_shortcode( 'search_form', 'search_form_shortcode' );
add_action( 'wp_head', 'search_css' );

function search_form_shortcode() {
    ob_start(); //start output buffering

        // check if form was submitted before attempting to process input or initialize the search term variable to an empty string.
        if ( isset( $_POST['_search_form_nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['_search_form_nonce'] ), 'search_form' ) ) {
            $search_term = sanitize_text_field( wp_unslash( $_POST['sf'] ) );
        } else {
            $search_term = '';
        }
?>

    <!-- Display the Search Form -->
        <form method="post" action="">
            <?php wp_nonce_field( 'search_form', '_search_form_nonce' ); ?>
            <input type="text" id="search-input" aria-label="Search input" name="sf" value="<?php echo esc_attr( $search_term ); ?>" required>
        <input type="submit" id="search-btn" aria-label="Search button" value="Search">
    </form>
<?php

        // Ensure there is a search term before making the API request to avoid empty requests.
    if ( ! empty( $search_term ) ) {
        $API_url = 'https://news.google.com/rss/search?q=' . rawurlencode( $search_term ) . '&hl=en-US&gl=US&ceid=US:en';

        $args = array( // add a user-agent and timeout to the request for better compatibility and reliability
            'timeout'    => 10,
            'user-agent' => 'Mozilla/5.0 (compatible)',
        );

        $results = wp_remote_get( $API_url, $args ); // make the HTTP request

        if ( is_wp_error( $results ) ) { // check for errors, for users 
            echo '<p id="search-error">Error fetching results. Please try again later.</p>';
        } else { // if no errors, process the response and seperate into code, headers, and body for easier processing
            $code = wp_remote_retrieve_response_code( $results );
            $headers = wp_remote_retrieve_headers( $results );
            $content_type = isset( $headers['content-type'] ) ? $headers['content-type'] : '';

            if ( intval( $code ) !== 200 ) { // check for a non-200 response code
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { // check for the error if debugging is enabled
                    error_log( '[search-form] Remote responded with HTTP ' . intval( $code ) . ' for URL: ' . $API_url );
                }
                echo '<p id="search-error">Error fetching results (HTTP ' . intval( $code ) . '). Please try again later.</p>';
            } else {
                $body = wp_remote_retrieve_body( $results );

                // remove common leading whitespace and BOM if present
                $body = ltrim( $body ); // remove leading whitespace
                $body = preg_replace('/^\xEF\xBB\xBF/', '', $body); // remove Byte Order Mark (BOM)

                if ( empty( $body ) ) {
                    echo '<p id="search-error">No response from remote server.</p>';
                } else {
                    libxml_use_internal_errors( true ); // suppress xml parsing errors

                    $xml = simplexml_load_string( $body );

                    // If parse failed, attempt to find XML prolog or first <rss> and parse that substring.
                    if ( $xml === false ) {
                        $xml = false;
                        $pos = strpos( $body, '<?xml' ); // try to find xml prolog
                        if ( $pos === false ) {
                            $pos = stripos( $body, '<rss' ); // try to find <rss> tag
                        }
                        if ( $pos !== false ) {
                            $try = substr( $body, $pos ); // retrieve substring from that position
                            $xml = @simplexml_load_string( $try ); // attempt to parse that substring
                        }

                        if ( $xml === false ) { 
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                $trunc = substr( $body, 0, 8192 ); // get first 8192 characters of body
                                error_log( '[search-form] simplexml_load_string failed. HTTP code: ' . intval( $code ) . '; Content-Type: ' . $content_type ); // log error details
                                error_log( '[search-form] Response (truncated): ' . $trunc );
                            }
                            // Show inline diagnostics to logged-in admins to aid debugging (only when WP_DEBUG is enabled).
                            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) { // if admin
                                echo '<div style="background:#fff;border:1px solid #ccc;padding:10px;margin:10px 0;">';
                                echo '<strong>Search Form debug — response could not be parsed as XML</strong>';
                                echo '<p>HTTP code: ' . intval( $code ) . ' — Content-Type: ' . esc_html( $content_type ) . '</p>';
                                echo '<pre style="white-space:pre-wrap;max-height:400px;overflow:auto">' . esc_html( substr( $body, 0, 16384 ) ) . '</pre>';
                                echo '</div>';
                            } else {
                                echo '<p id="search-error">Unable to parse response.</p>'; // otherwise, show generic error
                            }
                        } else {
                            // parsed from substring
                        }
                    } else {
                        // parsed successfully directly
                    }
                    
                    $items = array(); // build a normalized array of SimpleXMLElement items
                    if ( isset( $xml->channel->item ) ) { 
                        foreach ( $xml->channel->item as $it ) {
                            $items[] = $it;
                        }
                    }

                    if ( empty( $items ) ) { // if no items are found under channel, find items using xpath
                        $xpath_items = $xml->xpath('//item');
                        if ( $xpath_items && count( $xpath_items ) ) { 
                            $items = $xpath_items; 
                        }
                    }

                    echo '<h2>Search Results for:   ' . esc_html( $search_term ) . '</h2>';
                    if ( ! empty( $items ) ) { 
                        echo '<ul class="search-results">';
                        foreach ( $items as $item ) {
                            if ( is_object( $item ) && isset( $item->link ) ) {
                                $link  = (string) $item->link; 
                                $title = (string) $item->title; 
                                echo '<li><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a></li>';
                            } else { // log non-object or missing link entries
                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { 
                                    error_log( '[search-form] Skipping non-object <item> entry: ' . wp_trim_words( maybe_serialize( $item ), 30, '...' ) );
                                }
                                continue;
                            }
                        }
                        echo '</ul>';
                    } else { 
                        echo '<p>No results found.</p>';
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[search-form] No <item> found. Payload (truncated): ' . substr( $body, 0, 8192 ) );
                        }
                    }
                }
            }
        }
    } else {
        echo '<p id="search-prompt">Please enter a search term above to search Google News.</p>';
    }

    return ob_get_clean(); // return the buffered output
}


// CSS styles for the search form and results
function search_css() {
    echo '<style type="text/css">'
        . 'h1 {
            font-family: monospace, monospace;
            color: #6d350eff;
            text-align: center;
            margin-bottom: 20px;
            font-size: 42px;
        }'

        . 'form { 
            margin-bottom: 20px; 
            text-align: center;
        }'

        . '#search-input {
            padding: 12px;
            font-size: 20px;
            margin-right: 10px;
            width: 500px;
            max-width: 80%;
        }'

        . '#search-btn {
            font-family: monospace, monospace;
            padding: 12px;
            font-size: 20px;
            background-color: #B69078;
            border: 1px solid #7B3D10;
            box-shadow: 0px 2px 7px 1px #7B3D10;
        }' 
        . '#search-btn:hover {
            box-shadow: 0px 1px 5px 1px inset #7B3D10;
            text-shadow: 0px 0px 2px #7B3D10;
            cursor: pointer;
        }'

        . '#search-prompt {
            padding-left: 2em;
            padding-bottom: 3em;
        }'

        . ' h2 {
            font-family: monospace, monospace;
            text-align: left;
            margin-top: 40px;
            font-size: 22px;
        }'

        . '.search-results { 
                font-family: Times new roman, serif;
                list-style: none;
                font-size: 20px;
                padding-left: 10px;
        }'

        . '#search-error { 
                color: red; 
        }'

        . 'li {
            padding: .5em .3em;
        }'

        . 'li a {
            text-decoration-color: #7B3D10;
        }'

        . '.search-results li:nth-child(odd) {
            background-color: #eee8e2ff;
        }'

    . '</style>';
}