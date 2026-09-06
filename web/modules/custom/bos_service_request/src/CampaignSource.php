<?php

namespace Drupal\bos_service_request;

/**
 * Maps a campaign code (?c=) to a service_request `field_source` value, so BOS
 * reporting distinguishes channels (Google Ads vs Meta paid vs door hanger …)
 * instead of labelling everything "postcard_qr" / "other".
 *
 * Every value returned here MUST exist in field_source's allowed_values — kept
 * in sync by web/scripts/setup_campaign_source_values.php (see allValues()).
 * Unrecognized codes fall through to 'other' (always allowed), so a booking can
 * never fail to save because of an unmapped code.
 */
class CampaignSource {

  /**
   * Source value for a campaign code. Only call with an allowlisted code
   * (callers handle '' → website and unrecognized → 'other' themselves).
   */
  public static function forCode(string $code): string {
    $code = strtolower(trim($code));
    if ($code === '' || $code === 'website') {
      return 'website';
    }
    // Exact one-offs (neighborhood clusters, named promos).
    $exact = [
      'online26' => 'online_promo',
      'bearcreek26' => 'neighborhood',
    ];
    if (isset($exact[$code])) {
      return $exact[$code];
    }
    // Prefix rules — order matters (fbo before fb).
    $prefixes = [
      'pc' => 'postcard_qr',
      'goog' => 'google_ads',
      'fbo' => 'meta_organic',
      'fb' => 'meta_paid',
      'grp' => 'community_group',
      'door' => 'door_hanger',
      'react' => 'reactivation',
    ];
    foreach ($prefixes as $prefix => $source) {
      if (str_starts_with($code, $prefix)) {
        return $source;
      }
    }
    return 'other';
  }

  /**
   * All source values this mapper can emit, with labels — the authority for
   * field_source's allowed_values.
   */
  public static function allValues(): array {
    return [
      'website' => 'Website',
      'postcard_qr' => 'Postcard QR',
      'google_ads' => 'Google Ads',
      'meta_paid' => 'Meta/Facebook (Paid)',
      'meta_organic' => 'Meta/Facebook (Organic)',
      'community_group' => 'Community Group',
      'door_hanger' => 'Door Hanger',
      'reactivation' => 'Reactivation',
      'online_promo' => 'Online Promo',
      'neighborhood' => 'Neighborhood',
      'other' => 'Other',
    ];
  }

}
