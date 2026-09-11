<?php

/**
 * @file
 * Idempotent setup + copy sync for the /privacy page (a standard `page` node,
 * mirroring /about). On re-run the body is SYNCED to the canonical copy below.
 *
 *   ddev drush php:script web/scripts/setup_privacy_page.php      (dev)
 *   drush php:script web/scripts/setup_privacy_page.php           (live)
 *
 * Bracketed copy fields filled: [EMAIL] = office@brookstoneoutdoors.com,
 * [LAST-UPDATED] = the publish date. NAP matches the footer / Google Business
 * Profile. The SMS paragraph is INCLUDED (BOS models SMS consent, so texting is
 * in scope) — remove it if Brookstone does not text customers.
 *
 * NOT legal advice — a Colorado attorney should review before this carries real
 * weight; publishing an honest policy still closes the ads-platform gap now.
 */

use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

$aliasManager = \Drupal::service('path_alias.manager');
$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

$body = <<<'HTML'
<p class="legal-updated">Last updated: September 11, 2026</p>

<p>Brookstone Outdoors is a landscaping and property maintenance company serving Delta and Montrose counties in Colorado. This policy explains what information we collect, why we collect it, and what we do with it.</p>

<p>We have tried to write it in plain language. If anything here is unclear, call us at <a href="tel:9708359661">970-835-9661</a> and ask.</p>

<h2>Information you give us</h2>
<p>When you request an estimate, sign up for a service, fill out a form on this website, or become a customer, we collect what we need in order to do the work:</p>
<ul>
  <li>Your name, phone number, and email address</li>
  <li>The service address, and access details you tell us about, such as gate codes, dogs, or where to park</li>
  <li>What you are asking us to do, and any notes you provide</li>
  <li>Billing information necessary to invoice you</li>
</ul>
<p>We ask for this because we cannot maintain a property we cannot find or reach you about work we are scheduled to do. We do not ask for information we do not need.</p>

<h2>Information we create while working for you</h2>
<p>Doing the work generates records, and we keep them:</p>
<ul>
  <li>Service history — what was done at the property, when, and by which crew</li>
  <li>Property details such as irrigation zone counts, controller type, plant material, and system conditions we find</li>
  <li>Photographs of work in progress and completed work</li>
  <li>Pesticide and fertilizer application records, including product, rate, date, and conditions</li>
</ul>
<p>The application records are not optional. Colorado requires licensed commercial applicators to keep them, and we do.</p>

<h2>Information collected automatically on this website</h2>
<p>Like most websites, this one collects some technical information automatically:</p>
<ul>
  <li>IP address, browser type, and device type</li>
  <li>Pages viewed, time on the site, and the link or advertisement that brought you here</li>
  <li>Cookies and similar technologies set by the services listed below</li>
</ul>
<p>We use Google Tag Manager to manage these tools, Google Analytics to understand how the site is used, and Google Ads and Meta advertising tools to measure whether our advertising works.</p>

<h2>Advertising and remarketing</h2>
<p>We advertise on Google and on Facebook and Instagram, and we use those platforms' tools to measure results and to show our advertisements to people who have visited this site. This is commonly called remarketing.</p>
<p>In practical terms: if you visit this website, you may later see a Brookstone Outdoors advertisement on Google, Facebook, or Instagram. Those platforms use cookies and similar identifiers to make that happen, and they collect information about your visit under their own privacy policies, which we do not control.</p>
<p><strong>You can turn this off.</strong> These controls work across every advertiser, not only us:</p>
<ul>
  <li>Google advertising settings: <a href="https://adssettings.google.com" target="_blank" rel="noopener">adssettings.google.com</a></li>
  <li>Facebook and Instagram ad preferences: in your account settings under Ads</li>
  <li>Network Advertising Initiative opt-out: <a href="https://optout.networkadvertising.org" target="_blank" rel="noopener">optout.networkadvertising.org</a></li>
  <li>Your browser's tracking protection or Global Privacy Control setting</li>
</ul>
<p>We do not receive your name or contact information from these advertising platforms. We see counts and trends, not individual people.</p>

<h2>Email and text messages</h2>
<p><strong>Email.</strong> If you are a customer, we send you email about the work you hired us to do — scheduling, confirmations, invoices, and service notices. That is part of doing business together.</p>
<p>Marketing email is separate and we only send it if you have asked for it. You will not be added to a marketing list because you called us about a sprinkler repair. Every marketing email includes an unsubscribe link, and unsubscribing does not stop the service email you need in order to know when we are coming.</p>
<p><strong>Text messages.</strong> We may send text messages about scheduling, crew arrival, and weather-related changes to service. Message and data rates may apply. Reply STOP to any message to stop receiving them, though doing so means we will contact you by phone or email instead.</p>

<h2>Photographs of work we perform</h2>
<p>We photograph our work. Most of those photographs exist for practical reasons — documenting conditions before and after, recording what was installed, and settling questions later about what was done.</p>
<p>We may also use photographs of completed work in our portfolio, on this website, and in advertising.</p>
<p>When we do that for residential work, we show the landscape, not the household. We do not publish street addresses with residential project photographs, and we remove location data from images before they are published. Commercial and homeowners association work is treated differently, because there is a property manager or board to ask, and we ask them.</p>
<p><strong>If you would rather we did not use photographs of your property, tell us and we will not.</strong> That request applies going forward and to anything already published that you point us to. Call <a href="tel:9708359661">970-835-9661</a> or email <a href="mailto:office@brookstoneoutdoors.com">office@brookstoneoutdoors.com</a>.</p>

<h2>Who we share information with</h2>
<p>We do not sell your personal information. We have never sold it and we have no plans to.</p>
<p>We share information only where it is necessary to run the business:</p>
<ul>
  <li><strong>Service providers</strong> who work on our behalf — our website host, our email provider, our accounting system, and payment processors. They may use your information only to provide their service to us.</li>
  <li><strong>Advertising and analytics platforms</strong>, as described above, which receive website activity data rather than your customer records.</li>
  <li><strong>Your water provider</strong>, when we perform a backflow test on your irrigation system and file the required test report on your behalf.</li>
  <li><strong>When the law requires it</strong>, or to protect our rights, our employees, or someone's safety.</li>
</ul>
<p>Our customer records live in software we built and run ourselves, on servers we control. They are not stored in a third-party sales platform that resells access to them.</p>

<h2>How long we keep information</h2>
<p>We keep customer and property records for as long as you are a customer and afterward, because service history is what makes the next visit better than the last. Knowing what was installed in 2019 and what failed in 2022 is the reason we can diagnose a problem quickly.</p>
<p>Some records we are required to keep. Pesticide application records and backflow test reports have retention requirements set by the state and by water providers, and financial records have their own.</p>
<p>If you would like your information removed, contact us and we will do what we can. We will tell you plainly if something has to be retained for a legal reason.</p>

<h2>Security</h2>
<p>We take reasonable measures to protect the information we hold. Access to customer records is limited to employees who need it to do their jobs, the website uses encrypted connections, and our systems are kept current.</p>
<p>No system is perfectly secure, and any company that tells you otherwise is overstating. What we can tell you is that we do not keep information we do not need, and we do not put customer records anywhere they do not have to be.</p>

<h2>Your choices</h2>
<p>You may:</p>
<ul>
  <li>Ask what information we have about you</li>
  <li>Ask us to correct anything that is wrong</li>
  <li>Ask us to delete information, subject to the retention requirements above</li>
  <li>Unsubscribe from marketing email at any time</li>
  <li>Ask us not to use photographs of your property</li>
  <li>Opt out of advertising cookies using the controls listed earlier</li>
</ul>
<p>To make any of these requests, call <a href="tel:9708359661">970-835-9661</a> or email <a href="mailto:office@brookstoneoutdoors.com">office@brookstoneoutdoors.com</a>. We will respond within a reasonable time, and we are not going to make it difficult.</p>

<h2>Children</h2>
<p>This website is not directed to children, and we do not knowingly collect information from anyone under 13.</p>

<h2>Changes to this policy</h2>
<p>If we change how we handle information, we will update this page and change the date at the top. Material changes will be noted here rather than made quietly.</p>

<h2>Contact us</h2>
<p class="legal-contact">
  <strong>Brookstone Outdoors</strong><br>
  20143 Austin Rd<br>
  Austin, CO 81410
</p>
<p class="legal-contact">
  <a href="tel:9708359661">970-835-9661</a><br>
  <a href="mailto:office@brookstoneoutdoors.com">office@brookstoneoutdoors.com</a>
</p>
HTML;

// ---------------------------------------------------------------------------
// Node — create if /privacy is not already a node; else sync the body.
// ---------------------------------------------------------------------------
$existingSource = $aliasManager->getPathByAlias('/privacy');
$node = NULL;
if ($existingSource !== '/privacy' && preg_match('#^/node/(\d+)$#', $existingSource, $m)) {
  $node = $nodeStorage->load($m[1]);
}

if ($node) {
  $current = $node->get('body')->value ?? '';
  if (trim($current) === trim($body)) {
    echo "• /privacy node (nid {$node->id()}) body already matches canonical copy.\n";
  }
  else {
    $node->set('body', ['value' => $body, 'format' => 'full_html']);
    $node->save();
    echo "• Synced /privacy node (nid {$node->id()}) to the approved policy copy.\n";
  }
}
else {
  $node = Node::create([
    'type' => 'page',
    'title' => 'Privacy Policy',
    'uid' => 1,
    'status' => 1,
    'body' => ['value' => $body, 'format' => 'full_html'],
  ]);
  $node->save();
  echo "• Created /privacy page node nid {$node->id()}.\n";
}

$source = '/node/' . $node->id();
$aliasExists = \Drupal::entityTypeManager()->getStorage('path_alias')->getQuery()
  ->condition('alias', '/privacy')->condition('path', $source)
  ->accessCheck(FALSE)->range(0, 1)->execute();
if ($aliasExists) {
  echo "• Alias /privacy already present.\n";
}
else {
  PathAlias::create(['path' => $source, 'alias' => '/privacy', 'langcode' => 'en'])->save();
  echo "• Created alias /privacy → {$source}.\n";
}
echo "Done.\n";
