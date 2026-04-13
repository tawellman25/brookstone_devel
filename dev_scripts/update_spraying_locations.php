<?php

/**
 * Drush PHP script: Update spraying_locations taxonomy terms.
 *
 * Updates field_teammate_description and description (public) for all 18
 * spraying_locations terms.
 *
 * Usage (on live server):
 *   drush php:script update_spraying_locations.php
 *
 * Safe to run multiple times — overwrites only the two target fields.
 * Does not create or delete terms.
 */

$terms = [

  // -------------------------------------------------------------------------
  // TID 1405 — Arena
  // -------------------------------------------------------------------------
  1405 => [
    'public' => 'Horse and livestock arenas present unique vegetation management challenges. Weed pressure along arena perimeters, fence lines, and adjacent surfaces can undermine footing integrity and compete with managed turf areas on your property.

Brookstone Outdoors provides targeted weed control services specifically designed for equestrian and livestock environments. We understand that the animals using these spaces are sensitive to chemical exposure, and we take that responsibility seriously.

Our licensed applicators use only products with explicit livestock-safe designations for arena environments. We select herbicides based on the specific livestock present — horses, cattle, and other animals have different sensitivities and re-entry interval requirements.

We communicate re-entry intervals clearly before leaving every job, so you know exactly when the area is safe for your animals. Our records document livestock presence, product used, and re-entry timing for every arena application.

Effective arena perimeter management keeps your facility clean, professional, and safe for the animals and people who use it every day. Brookstone Outdoors is your licensed partner for responsible weed control in livestock environments.',

    'teammate' => 'ARENA — TEAMMATE INSTRUCTIONS

WHAT THIS IS
A horse or livestock arena — typically dirt, sand, or decomposed granite floor — where weed control is applied along perimeter edges, fence lines, and any adjacent non-arena surfaces. Livestock presence is variable; confirm before arriving.

UNIQUE CONSIDERATIONS
- Livestock safety is the primary concern. Many herbicides are not labeled for use where horses or livestock will graze or contact treated soil.
- Arena footing (sand, DG, road base) is porous — product can move through it quickly and reach the soil layer below.
- Perimeter weeds are the primary target. Spraying inside the arena floor is rarely appropriate and requires specific label authorization.
- Dust and loose footing create PPE challenges — goggles and respiratory protection are especially important.
- Horses are sensitive to chemical odors and may become agitated if spraying occurs while they are present.

PRODUCT RESTRICTIONS
- Use only products labeled safe for use in and around livestock areas.
- Do not use products with soil residual activity inside the arena unless the label explicitly permits it in livestock environments.
- Avoid oil-based carriers inside the arena — they can alter footing and create slip hazards.
- Pre-emergent products inside the arena floor require supervisor approval and explicit label authorization.

ENVIRONMENTAL & LEGAL FLAGS
- If the arena is adjacent to a water feature, drainage channel, or pond, standard aquatic buffer rules apply to that edge — see Retention Pond term.
- Colorado CDA requires all spray records to document livestock presence at time of application.
- If livestock were present during application, document that fact and the product\'s re-entry interval (REI) in your spray record.

FIELD RULES — DO
- Confirm livestock status before unloading equipment — call the client if unsure.
- If livestock are present, assess whether spraying can proceed given product label REI requirements.
- Spray perimeter and fence line edges only unless specifically directed otherwise.
- Keep spray pressure low to minimize drift onto arena footing.
- Notify the client of re-entry interval — do not assume they know.

FIELD RULES — DO NOT
- Do not spray inside the active arena floor without supervisor sign-off.
- Do not spray while horses are in or immediately adjacent to the treatment area.
- Do not use products not labeled for livestock environments.

RECORD-KEEPING NOTES
Document livestock presence (present / absent / unknown), re-entry interval of product used, and which areas were treated (perimeter, fence line, exterior only). Note if treatment was deferred due to livestock and rescheduled.',
  ],

  // -------------------------------------------------------------------------
  // TID 1449 — Fence Line
  // -------------------------------------------------------------------------
  1449 => [
    'public' => 'Fence lines are some of the most persistent weed corridors on any property. Weeds that establish along fence bases quickly spread into adjacent lawn, beds, and neighboring parcels — making early and consistent control essential.

Brookstone Outdoors provides precise, targeted herbicide application along all fence types — wood, metal, vinyl, and wire — using directed wand application that keeps product exactly where it\'s needed.

We take property boundaries seriously. Our applicators are trained to work on your side of the fence line and to protect neighboring properties from drift or runoff. We assess wind conditions before every application and reposition when needed.

Before any fence line treatment, we walk the line to identify any desirable vines, ornamental climbers, or intentional plantings that should be protected. We treat what you need treated — nothing more.

Consistent fence line management keeps your property boundaries clean, prevents weed encroachment into adjacent areas, and protects the structural integrity of wood and metal fencing from moisture-holding weed growth.',

    'teammate' => 'FENCE LINE — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Vegetation growing at the base of or immediately adjacent to a fence — wood, metal, vinyl, or wire — on residential, commercial, or rural properties. Fence lines are a common weed corridor and require precise application to avoid damage to fence materials and adjacent desirable plants.

UNIQUE CONSIDERATIONS
- Fence lines are often the boundary between your client\'s property and a neighbor\'s — drift across that line is a trespass and liability issue.
- Wood fencing can be stained or discolored by some herbicide formulations, especially oil-based carriers.
- Fence lines on rural properties frequently border pasture, livestock areas, or irrigation ditches — each of which has its own constraints.
- Desirable vines, climbing roses, or ornamental plants are sometimes intentionally growing on the fence — confirm with the client what stays and what goes before spraying.
- Grass encroachment along fence bases is the most common target — use a shielded sprayer or directed wand to keep product at the base.

PRODUCT RESTRICTIONS
- Avoid high-volume broadcast spraying along fence lines — directed wand application only.
- If the fence borders a pasture or livestock area, product must be labeled for those environments.
- If the fence borders an irrigation ditch or waterway, aquatic buffer rules apply — see Retention Pond term.
- Do not use oil-based carriers against wood or vinyl fencing without confirming compatibility.

ENVIRONMENTAL & LEGAL FLAGS
- Property boundary awareness is mandatory. Know where the property line is before spraying.
- Drift onto a neighbor\'s ornamental plants or lawn is a liability event — wind conditions must be favorable before starting.
- If the fence borders a CDOT or county right-of-way, stop and call your supervisor — that edge has different regulatory requirements.

FIELD RULES — DO
- Walk the fence line before spraying — identify desirable plants, property boundaries, and adjacent sensitive areas.
- Confirm with client which plants along the fence are intentional.
- Use a directed wand or shielded sprayer — no broadcast application along fence lines.
- Stay on your client\'s side of the fence.

FIELD RULES — DO NOT
- Do not spray in wind conditions that could carry product across the fence line.
- Do not assume everything growing on the fence is a weed.
- Do not apply oil-based carriers directly against wood or vinyl fence material.

RECORD-KEEPING NOTES
Note fence type (wood, metal, wire, vinyl), adjacent land use on the other side (neighbor lawn, pasture, ditch, ROW), and application method used. Document any areas skipped and why.',
  ],

  // -------------------------------------------------------------------------
  // TID 1404 — Pathway
  // -------------------------------------------------------------------------
  1404 => [
    'public' => 'Pathways are often the first thing visitors notice about your outdoor spaces. Weeds growing through gravel, flagstone joints, and decomposed granite surfaces make even a well-maintained landscape look unkempt.

Brookstone Outdoors provides precise weed control for all pathway types — gravel, flagstone, stepping stone, decomposed granite, and packed dirt — using application methods matched to each surface and the plantings that border them.

We understand that pathways are bordered by the plants you\'ve invested in. Our applicators use directed, low-pressure application techniques that keep herbicide on the pathway surface and away from adjacent ornamentals and ground cover.

For flagstone and stepping stone pathways, we use pinpoint applicator tips to treat individual joints and cracks — not a broad spray that risks overspray onto surrounding landscape. The result is a clean, weed-free path without collateral damage.

Regular pathway weed control protects your landscape investment, maintains the aesthetic of your outdoor spaces, and keeps foot traffic areas safe and presentable for your family, guests, and clients.',

    'teammate' => 'PATHWAY — TEAMMATE INSTRUCTIONS

WHAT THIS IS
A designated walking or pedestrian path — gravel, decomposed granite, flagstone, stepping stones, or packed dirt — where weed control is applied to suppress growth in and along the path surface. Distinct from driveways (vehicle traffic) and sidewalks (concrete infrastructure).

UNIQUE CONSIDERATIONS
- Pathways are often bordered closely by landscape beds, lawn, or ornamental plantings — precision is critical.
- Gravel and DG pathways are porous — product moves through quickly and can reach soil below, affecting adjacent plant root zones if applied too heavily.
- Flagstone and stepping stone pathways have cracks and joints where weeds root — targeted crack application is usually more appropriate than broadcast.
- High-foot-traffic pathways require attention to re-entry intervals and wet product slip hazards.
- Some pathways lead directly to doors, patios, or play areas — keep that in mind when selecting products and application rates.

PRODUCT RESTRICTIONS
- Soil sterilant products are generally not appropriate for pathways bordered by landscape beds or lawn — residual activity can migrate and damage adjacent plantings.
- Use non-residual or short-residual products where the pathway borders ornamentals within 2–3 feet.
- For flagstone/stepping stone crack treatment, a targeted applicator tip (crack-and-crevice or pinpoint) is preferred over a flat fan tip.

ENVIRONMENTAL & LEGAL FLAGS
- If the pathway drains toward a pond, stream, or stormwater inlet, treat it with the same care as a buffer zone application.
- Pathways near vegetable gardens or edible plantings require products explicitly labeled safe for use near food crops — confirm before applying.

FIELD RULES — DO
- Walk the entire pathway before spraying — identify borders, drainage direction, and adjacent plant types.
- Use directed, low-pressure application — keep product on the path surface.
- Notify the client of re-entry interval, especially on high-traffic paths.
- For crack/joint treatment, use a pinpoint or crack tip for precision.

FIELD RULES — DO NOT
- Do not broadcast spray a pathway directly bordered by ornamental beds on both sides — directed application only.
- Do not use soil sterilants within 3 feet of desirable plantings.
- Do not spray in wet conditions — product on hard surfaces creates slip hazards.

RECORD-KEEPING NOTES
Note pathway surface type (gravel, DG, flagstone, dirt), application method, and proximity to adjacent plantings. Note any sections skipped or treated differently and why.',
  ],

  // -------------------------------------------------------------------------
  // TID 1770 — Sidewalk Cracks
  // -------------------------------------------------------------------------
  1770 => [
    'public' => 'Weeds pushing through sidewalk cracks are one of the most visible maintenance issues on any residential or commercial property. They signal neglect, accelerate concrete deterioration, and create trip hazards over time.

Brookstone Outdoors provides targeted crack-and-joint herbicide treatment for concrete and asphalt sidewalks, using precision applicator tips that direct product exactly into the crack — not onto adjacent lawn or bed areas.

Our applicators are trained to work close to turf edges without causing damage. We assess which product is appropriate based on what borders the sidewalk and apply at rates and methods that protect your lawn and landscape while eliminating the weeds.

We also pay attention to your stormwater infrastructure. Sidewalk applications near storm drain inlets are timed and applied in a way that minimizes runoff, keeping our work environmentally responsible on every job.

The result is a clean, professional sidewalk surface that reflects well on your property. Regular crack treatment prevents weed roots from widening existing cracks and reduces long-term concrete repair costs.',

    'teammate' => 'SIDEWALK CRACKS — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Weed growth emerging through cracks, expansion joints, and edges of concrete or asphalt sidewalks. This is a precision application targeting the crack itself — not a broadcast treatment of the surrounding surface.

UNIQUE CONSIDERATIONS
- This is one of the most visible weed problems clients notice — results matter and are highly visible to neighbors and passersby.
- Concrete and asphalt are impermeable — product applied to the crack goes directly to the root zone. Less product is usually more effective here.
- Sidewalks in residential areas often have lawn growing right to the edge — overspray onto turf with a non-selective herbicide causes immediate visible damage.
- Sidewalk edges bordering lawn require a shielded or pinpoint applicator — no flat fan spray.
- High pedestrian traffic areas require attention to wet product — a wet sidewalk is a slip hazard and a liability.

PRODUCT RESTRICTIONS
- Non-selective herbicides (glyphosate-based) are appropriate for crack treatment but must be applied with precision to avoid turf contact.
- Soil sterilant products are generally not appropriate in residential sidewalk settings — residual can migrate under the slab and emerge in unintended areas.
- Do not use products with long soil residual activity where sidewalk edges border lawn or ornamental beds.

ENVIRONMENTAL & LEGAL FLAGS
- Sidewalks that drain to storm drains or curb inlets are connected to the municipal stormwater system — minimize runoff by using low-volume, targeted applications.
- Do not apply to sidewalks immediately before rain — product will wash directly into storm drains.

FIELD RULES — DO
- Use a crack-and-crevice or pinpoint applicator tip — no flat fan on crack treatment.
- Apply at low pressure directly into the crack.
- Keep product off adjacent turf and bed areas.
- Allow product to dry before foot traffic resumes — wet product is a slip hazard.

FIELD RULES — DO NOT
- Do not broadcast spray sidewalk surfaces — targeted crack treatment only.
- Do not spray before rain is forecast.
- Do not use soil sterilants in residential sidewalk settings without supervisor approval.

RECORD-KEEPING NOTES
Note surface type (concrete, asphalt), application method (crack tip, pinpoint wand), proximity to adjacent turf or beds. Note weather conditions and approximate dry time before foot traffic.',
  ],

  // -------------------------------------------------------------------------
  // TID 1202 — Spot Spray Beds
  // -------------------------------------------------------------------------
  1202 => [
    'public' => 'Landscape beds represent one of the most significant investments in your outdoor spaces — and weeds are their most persistent threat. Left unchecked, weeds compete directly with your ornamentals for water, nutrients, and light.

Brookstone Outdoors provides precise spot-spray weed control in established landscape beds, targeting individual weeds and weed clusters without harming the surrounding plants you\'ve invested in.

Our licensed applicators identify each weed before treating and use directed wand application at low pressure to deliver product exactly where it\'s needed. We shield adjacent ornamentals when necessary and never broadcast spray in established bed areas.

We assess wind conditions before every application and reposition when needed to ensure product stays on target. We also evaluate mulch depth, irrigation systems, and soil conditions that could affect how product moves through the bed.

Regular spot-spray maintenance keeps your landscape beds looking their best between major service visits and protects the health and longevity of the plants that define your outdoor spaces.',

    'teammate' => 'SPOT SPRAY BEDS — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Targeted herbicide application to individual weeds or weed clusters within established landscape beds. This is precision work — the goal is to kill the weed without harming surrounding ornamental plants, ground cover, or mulch.

UNIQUE CONSIDERATIONS
- Landscape beds contain desirable plants that are often the most expensive part of the property — damage here is immediately visible and costly.
- Mulch absorbs herbicide and can hold product against plant stems — avoid spraying directly onto mulch at the base of desirable plants.
- Many ornamentals are susceptible to herbicide drift even at low concentrations — wind conditions matter more here than almost any other location.
- Spot spraying beds requires active weed identification — you must know what you\'re targeting and what you\'re protecting before pulling the trigger.
- Drip irrigation systems and emitters may be present in bed areas — avoid saturating soil around emitters.

PRODUCT RESTRICTIONS
- Non-selective herbicides (glyphosate) require extreme precision in beds — directed wand application only, no broadcast.
- Selective herbicides (grass killers in beds) may be appropriate for grass encroachment in ornamental beds — confirm product and label before applying.
- Do not use pre-emergent products in established beds without confirming they are labeled safe for the specific ornamentals present.
- Avoid contact with plant stems, foliage, and exposed root flares.

ENVIRONMENTAL & LEGAL FLAGS
- Beds adjacent to water features, ponds, or drainage channels — see Retention Pond term for buffer rules.
- Beds near vegetable gardens require products labeled safe for use near food crops.

FIELD RULES — DO
- Walk the bed before spraying — identify all desirable plants and weed targets.
- Use a directed wand with a flat fan or cone tip at low pressure.
- Shield adjacent desirable plants with your hand or a piece of cardboard if necessary.
- Spray weeds at the base — avoid foliage contact with desirable plants.
- Check wind — if it\'s moving product toward desirable plants, reposition or wait.

FIELD RULES — DO NOT
- Do not broadcast spray into landscape beds.
- Do not spray in wind conditions that could carry product onto desirable foliage.
- Do not spray mulch heavily at the base of ornamental plants.

RECORD-KEEPING NOTES
Note bed location on property, product used, target weed species if identifiable, and any areas avoided. Document any desirable plants that were at risk and how they were protected.',
  ],

  // -------------------------------------------------------------------------
  // TID 1203 — Spot Spray Lawn
  // -------------------------------------------------------------------------
  1203 => [
    'public' => 'Weeds in your lawn don\'t have to mean a whole-lawn chemical treatment. Spot spraying targets the problem areas — a dandelion patch, clover infestation, or isolated broadleaf weeds — without treating grass that doesn\'t need it.

Brookstone Outdoors provides targeted spot-spray lawn weed control, identifying and treating specific weed pressure areas while leaving surrounding healthy turf undisturbed.

Our licensed applicators use selective herbicides matched to the specific weed species and grass type present on your property. The right product in the right place means effective weed control with minimal impact on your turf.

We assess temperature, wind, and moisture conditions before every application — selective herbicides can volatilize in high heat and drift to adjacent landscape areas, so we time applications for conditions that protect your whole property.

Spot-spray lawn care is an efficient, targeted approach to maintaining a healthy, weed-free lawn without unnecessary chemical application across areas that are already performing well.',

    'teammate' => 'SPOT SPRAY LAWN — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Targeted herbicide application to individual weeds or weed clusters within an established turf lawn. Unlike full lawn treatment, spot spraying targets specific problem areas without treating the entire lawn.

UNIQUE CONSIDERATIONS
- Spot spraying requires accurate weed identification — applying the wrong product to the wrong weed wastes product and can damage turf.
- Turf type matters — cool-season grasses (fescue, bluegrass) and warm-season grasses have different herbicide tolerances. Confirm grass type before selecting product.
- Newly seeded or overseeded areas are highly sensitive — most selective herbicides cannot be applied until turf is established (typically 2–3 mowings minimum).
- Spot spraying is not a substitute for a full lawn treatment when weed pressure is high — flag to your supervisor and the client if weeds cover more than 30% of the area.
- Irrigation heads are common in lawn areas — mark their location and avoid saturating around heads.

PRODUCT RESTRICTIONS
- Use selective broadleaf herbicides (2,4-D, dicamba, triclopyr combinations) for broadleaf weeds in turf — non-selective products will kill the grass.
- Confirm the product is labeled for the specific grass type present.
- Do not apply selective herbicides to newly seeded turf — wait for establishment.
- Do not apply during heat stress (temps above 85F) — volatilization and drift risk increases significantly.

ENVIRONMENTAL & LEGAL FLAGS
- Lawns adjacent to garden beds, vegetable gardens, or water features — selective herbicides can drift and damage broadleaf ornamentals.
- Do not apply selective herbicides on hot, still days — volatilization risk is high.
- Document any turf damage observed prior to treatment — this protects Brookstone if a client later claims herbicide damage.

FIELD RULES — DO
- Identify the weed and confirm the correct product before applying.
- Confirm grass type — cool season vs. warm season.
- Check air temperature — avoid applying above 85F.
- Use a flat fan tip at low pressure for directed spot application.
- Flag high weed-pressure areas for supervisor review.

FIELD RULES — DO NOT
- Do not use non-selective herbicide in an established lawn.
- Do not spot spray newly seeded areas.
- Do not spray when wind is moving product toward ornamental beds.

RECORD-KEEPING NOTES
Document grass type, weed species targeted, product and rate used, air temperature at time of application, and approximate percentage of lawn affected. Note any areas deferred and reason.',
  ],

  // -------------------------------------------------------------------------
  // TID 1159 — Lawn
  // -------------------------------------------------------------------------
  1159 => [
    'public' => 'A weed-free lawn starts with a licensed professional who understands the difference between the grass you want and the weeds you don\'t. Broadcast lawn treatment is a precision service when done right — and a liability when done wrong.

Brookstone Outdoors provides full-lawn broadcast weed control using selective herbicides matched to your specific turf type. We measure treatment areas accurately, calibrate our equipment before every job, and apply at label-correct rates.

We assess turf health before every treatment. Stressed or newly seeded lawns require different timing and product choices than established, healthy turf — and we adjust accordingly to protect your investment.

Re-entry interval communication is standard on every lawn treatment. We\'ll tell you exactly when children, pets, and foot traffic can return to the treated area — in writing if you prefer.

Our broadcast lawn treatments are performed under favorable weather conditions — wind speed and direction, air temperature, and moisture are all factored in before we start. The goal is effective weed control that protects your lawn, your landscape, and your neighbors\' property.',

    'teammate' => 'LAWN — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Broadcast herbicide application across an entire established turf lawn — typically a full-property weed control treatment rather than targeted spot work.

UNIQUE CONSIDERATIONS
- Full lawn broadcast application requires accurate measurement of the treatment area for proper product rate calculation — do not estimate square footage.
- Lawn areas frequently contain irrigation systems, decorative features, garden beds, and trees — all of which require awareness during broadcast application.
- Neighboring lawns, beds, and properties are at risk if wind conditions are not favorable — broadcast application has higher drift potential than spot treatment.
- Clients often have pets and children — re-entry interval communication is especially important on full lawn treatments.
- Dormant or stressed turf is more susceptible to herbicide injury — assess turf health before applying.

PRODUCT RESTRICTIONS
- Use only selective broadleaf herbicides for standard lawn treatment — non-selective products are never appropriate for broadcast lawn application.
- Confirm product is labeled for the specific grass type.
- Do not apply when turf is under heat or drought stress.
- Do not apply to lawns recently seeded or sodded (minimum 2–3 mowings or per label direction).

ENVIRONMENTAL & LEGAL FLAGS
- Full lawn broadcast treatment near ornamental beds requires a buffer or extreme care at the edges — selective herbicides will damage broadleaf ornamentals.
- Lawns that drain toward storm drains, ditches, or water features — minimize runoff by applying at label-minimum rates and avoiding pre-rain application.
- Colorado CDA spray records for broadcast lawn treatment must include treatment area in square feet.

FIELD RULES — DO
- Measure or confirm square footage before mixing product.
- Check wind speed and direction — broadcast application requires calm conditions (under 10 mph).
- Identify and flag bed edges, trees, and garden areas before starting.
- Communicate re-entry interval to client, especially if children or pets are present.
- Assess turf health before applying — defer if lawn is under stress.

FIELD RULES — DO NOT
- Do not broadcast spray in wind above 10 mph.
- Do not apply to newly seeded or recently sodded turf.
- Do not apply selective herbicide within broadcast range of ornamental beds without edge control.

RECORD-KEEPING NOTES
Document treatment area in square feet, product and rate, wind speed and direction, air temperature, turf type, and re-entry interval communicated to client. Note any areas excluded from treatment and why.',
  ],

  // -------------------------------------------------------------------------
  // TID 1160 — On Gravel
  // -------------------------------------------------------------------------
  1160 => [
    'public' => 'Gravel areas are low-maintenance by design — but weeds that establish in aggregate surfaces are persistent, deep-rooted, and can quickly make a clean gravel area look neglected. Effective control requires the right product for the right surface.

Brookstone Outdoors provides specialized weed control for all gravel types — decorative rock, river rock, decomposed granite, road base, and crushed aggregate — using products matched to the specific surface and what borders it.

Gravel is a permeable surface, and we account for that in every application. We select products with appropriate residual activity for long-term control while ensuring those products won\'t migrate into adjacent lawn or ornamental root zones.

For isolated gravel areas — driveways, parking areas, utility zones — we can apply longer-residual products that provide season-long suppression with fewer applications. For gravel adjacent to landscape, we use shorter-residual options that protect surrounding plantings.

The result is a consistently clean gravel surface with significantly less weed re-establishment between service visits. Brookstone Outdoors keeps your gravel areas looking intentional, not abandoned.',

    'teammate' => 'ON GRAVEL — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Herbicide application to weed growth in gravel surfaces — decorative rock, road base, river rock, crushed granite, or similar aggregate areas used for ground cover, drainage, or traffic surfaces.

UNIQUE CONSIDERATIONS
- Gravel is permeable — product passes through aggregate quickly and reaches soil below. Residual herbicides work well here but must be selected carefully based on what borders the gravel area.
- Gravel areas adjacent to landscape beds or lawn require products that won\'t migrate into root zones of desirable plants.
- Weeds in gravel often have deep root systems — contact-only products may require follow-up; a residual product is usually more effective for long-term control.
- Some gravel areas have landscape fabric beneath — product penetrates fabric and reaches soil.
- Decorative rock near the home foundation may be near irrigation heads, downspouts, or HVAC equipment — be aware of these features during application.

PRODUCT RESTRICTIONS
- Soil sterilant or long-residual products are appropriate for gravel areas fully isolated from lawn and ornamental beds — confirm isolation before using.
- Do not use long-residual products in gravel areas within the root zone of adjacent trees or shrubs.
- Non-selective + residual combination products work well in gravel — confirm label permits use on aggregate/non-crop surfaces.

ENVIRONMENTAL & LEGAL FLAGS
- Gravel areas that drain toward storm drains, swales, or water features — treat with minimum effective rate; residual products can travel with drainage water.
- Gravel bordering a property line requires awareness of what\'s on the other side — do not apply residual products that could migrate across the boundary.

FIELD RULES — DO
- Identify what borders the gravel area on all sides before selecting product.
- Use a flat fan tip for even broadcast coverage.
- Apply residual products at label-minimum rate — more is not better in permeable surfaces.
- Note drainage direction — apply away from sensitive areas where possible.

FIELD RULES — DO NOT
- Do not use soil sterilants within root zone distance of trees, shrubs, or ornamentals.
- Do not apply long-residual products to gravel that drains directly to a water feature.
- Do not assume gravel is isolated — walk the perimeter first.

RECORD-KEEPING NOTES
Document gravel type, approximate area treated, product type (contact vs. residual), drainage direction, and what borders the gravel on each side. Note landscape fabric presence if observed.',
  ],

  // -------------------------------------------------------------------------
  // TID 1161 — Landscape Beds
  // -------------------------------------------------------------------------
  1161 => [
    'public' => 'Your landscape beds are the showcase of your outdoor spaces. Keeping them weed-free requires a professional who understands the difference between a target weed and a prized ornamental — and has the precision to act on that knowledge.

Brookstone Outdoors provides comprehensive landscape bed weed control, including both pre-emergent and post-emergent treatments, matched to the specific plants in your beds and the weed pressure you\'re dealing with.

Pre-emergent applications stop weed seeds from germinating before they become a visible problem. Our licensed applicators time pre-emergent treatments to your specific plant species and local soil temperature conditions — not a generic calendar date.

For established weed pressure, we use directed application techniques that target weed root zones while protecting adjacent ornamentals. We assess mulch depth, irrigation schedules, and bed drainage to choose the right product and rate for your specific beds.

Regular professional bed weed management reduces overall chemical use over time — preventing weed establishment is always more efficient than repeated eradication. Brookstone Outdoors is your long-term partner in keeping your landscape beds healthy and beautiful.',

    'teammate' => 'LANDSCAPE BEDS — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Broadcast or directed herbicide application to established landscape beds for weed suppression — applies when treating a larger bed area rather than individual weeds. Covers pre-emergent application, overall weed suppression, or beds with significant weed pressure.

UNIQUE CONSIDERATIONS
- Landscape beds contain the highest concentration of desirable, expensive plantings on most properties — this is the highest-risk spraying location for plant damage.
- Bed edges adjacent to lawn are a common drift zone — selective herbicides from lawn treatment and non-selective from bed treatment can each damage the other side.
- Mulched beds hold product at the surface — avoid heavy application directly onto mulch around plant stems and root flares.
- Pre-emergent applications in beds require timing relative to planting schedules — applying pre-emergent after new plants are installed can inhibit root establishment.
- Drip irrigation systems in beds can carry product through the soil after application — note irrigation schedules.

PRODUCT RESTRICTIONS
- Confirm any product used in landscape beds is labeled safe for use around the specific ornamentals present.
- Pre-emergent products must be labeled for ornamental bed use.
- Non-selective herbicides in beds require directed wand application only — no broadcast non-selective in established ornamental beds.
- Avoid applying contact herbicides immediately before or after irrigation.

ENVIRONMENTAL & LEGAL FLAGS
- Beds adjacent to water features — see Retention Pond term for buffer rules.
- Beds near vegetable or herb gardens — products must be labeled safe for use near food crops.
- Pre-emergent application timing relative to rain events — heavy rain shortly after application can move product off-site.

FIELD RULES — DO
- Walk the entire bed before applying — identify plant species, root flares, irrigation emitters, and weed pressure level.
- For pre-emergent: confirm no new planting is scheduled within the label\'s planting interval.
- For contact/non-selective: use directed wand only — no broadcast in established ornamental beds.
- Apply mulch-surface products carefully — keep away from stem bases.
- Check irrigation schedule — avoid treatment immediately before a scheduled irrigation cycle.

FIELD RULES — DO NOT
- Do not broadcast non-selective herbicide in established ornamental beds.
- Do not apply pre-emergent immediately after new plants are installed.
- Do not apply near vegetable beds without confirming food-crop label approval.

RECORD-KEEPING NOTES
Document bed location, treatment type (pre-emergent vs. contact vs. non-selective), product and rate, irrigation status, and any plants shielded or avoided. Note mulch depth if relevant to application rate decisions.',
  ],

  // -------------------------------------------------------------------------
  // TID 1162 — Tree Rings
  // -------------------------------------------------------------------------
  1162 => [
    'public' => 'The area around the base of a tree is one of the most important zones to keep weed-free — grass and weeds competing with trees at the root flare directly reduce tree growth, health, and long-term vitality.

Brookstone Outdoors provides careful, targeted weed control in tree rings, using products and application methods specifically selected for proximity to tree root systems. We treat what\'s in the ring without harming what the ring is protecting.

Our applicators are trained to keep herbicide off root flares, trunk bark, and exposed surface roots — the most sensitive parts of the tree. We work from the outer edge of the ring inward at low pressure, minimizing the risk of contact with the tree itself.

We match product selection to tree species and age. Young trees require more conservative rates and product choices than mature, established trees. We ask about tree age and note species on every tree ring job.

A clean, managed tree ring protects your tree investment, improves the appearance of your landscape, and eliminates the competition that slows tree establishment and canopy development over time.',

    'teammate' => 'TREE RINGS — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Herbicide application within the mulched or bare-soil ring around the base of a tree, targeting grass and weed encroachment. One of the most beneficial but also riskiest spraying locations — proximity to a tree\'s root flare and root zone requires careful product selection and application discipline.

UNIQUE CONSIDERATIONS
- Tree roots extend far beyond the visible trunk — often 2–3 times the canopy drip line radius. Residual herbicides in the ring can be taken up by feeder roots well beyond the ring edge.
- The root flare is the most sensitive part of the tree — product contact here can cause cambium damage and long-term decline.
- Young trees (less than 3 years established) are significantly more sensitive to herbicide exposure than mature trees.
- Grass growing into a tree ring competes directly with the tree for water and nutrients — weed control here is genuinely beneficial to tree health when done correctly.
- Some trees (ornamental cherries, crabapples, lindens) are more herbicide-sensitive than others — when in doubt, defer to non-chemical methods.

PRODUCT RESTRICTIONS
- Non-selective herbicides (glyphosate) can be used in tree rings with directed application — keep product off the root flare, trunk, and any exposed surface roots.
- Do not use soil sterilant or long-residual products in tree rings — root uptake over time will damage or kill the tree.
- Pre-emergent products in tree rings must be labeled safe for use around trees.
- Avoid herbicide contact with tree bark, exposed roots, and root flares at all times.

ENVIRONMENTAL & LEGAL FLAGS
- Trees are long-term assets — herbicide damage may not manifest for months or years. This is a liability risk if not documented properly.
- Large or specimen trees on high-value properties warrant extra caution — flag these to your supervisor before treating if you have any doubt.

FIELD RULES — DO
- Identify tree species before treating — note any known herbicide sensitivity.
- Use a directed wand with a flat fan tip at low pressure — keep product away from the trunk.
- Apply from the outer edge of the ring inward — finishing away from the trunk.
- Keep product off the root flare and any exposed surface roots.
- Note tree age — apply extra caution on trees less than 3 years established.

FIELD RULES — DO NOT
- Do not use soil sterilants or long-residual products in tree rings.
- Do not allow product contact with bark, root flare, or exposed roots.
- Do not apply in windy conditions that could carry product into the tree canopy.

RECORD-KEEPING NOTES
Document tree species, estimated age/size, product used, application method, and confirmation that root flare was avoided. Note any exposed surface roots and how they were protected.',
  ],

  // -------------------------------------------------------------------------
  // TID 1220 — Shrubs
  // -------------------------------------------------------------------------
  1220 => [
    'public' => 'Weeds at the base of established shrubs are more than an aesthetic problem — they compete directly with your shrubs for water, nutrients, and root space. Managing them requires a level of precision that separates professional applicators from DIY spray jobs.

Brookstone Outdoors provides targeted weed control beneath and around established shrubs using products specifically selected for compatibility with the shrub species present on your property. We don\'t apply a one-size-fits-all product — we match the herbicide to the plants.

Our licensed applicators work beneath shrub canopies with directed wand application, keeping product at the soil level where it reaches weed root zones without contacting shrub foliage, stems, or branches. This is close-quarters precision work.

We treat hedge rows as connected systems — consistent treatment along the full length of a hedge protects uniform root zone chemistry and prevents uneven results. We also assess whether selective grass herbicides or broader control products are appropriate based on what\'s growing.

Protecting the shrubs you\'ve invested in while eliminating the weeds competing with them is exactly the kind of detail work Brookstone Outdoors is built for. Your shrub borders will look clean and your plants will perform better for it.',

    'teammate' => 'SHRUBS — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Herbicide application targeting weeds growing at the base of or beneath established shrubs — grass encroachment, broadleaf weeds, or woody seedlings competing with shrubs in landscape or natural settings. This is not foliar treatment of the shrubs themselves.

UNIQUE CONSIDERATIONS
- Shrubs vary enormously in herbicide sensitivity — a product safe around junipers may damage roses.
- Shrub canopies create shaded, moist microclimates at their base — weeds in these zones are often more established and harder to kill.
- Low-hanging branches can intercept spray and transfer product to the shrub\'s foliage or stems — a directed wand under the canopy is almost always necessary.
- Root systems of shrubs spread laterally — residual products at the base move into the root zone quickly in sandy or loamy soils.
- Shrubs in rows (hedges, foundation plantings) often have continuous root zones — treat the row as a connected system, not individual plants.

PRODUCT RESTRICTIONS
- Selective grass herbicides (fluazifop, sethoxydim) are the safest option for grass control beneath broadleaf shrubs — confirm the shrub species is on the product label\'s tolerance list.
- Non-selective herbicides beneath shrubs require extreme precision — directed application at the soil level only, no contact with stems, branches, or leaves.
- Do not use soil sterilants or long-residual products beneath established ornamental shrubs.
- Pre-emergent products under shrubs must be labeled safe for use around the specific shrub species present.

ENVIRONMENTAL & LEGAL FLAGS
- Shrubs near water features — see Retention Pond term for buffer rules.
- Shrubs in natural or native plantings (xeriscape, native habitat areas) may include species with no herbicide tolerance data — defer to non-chemical methods in these settings unless directed otherwise by supervisor.

FIELD RULES — DO
- Identify shrub species before treating — match product to the specific plants present.
- Use a directed wand and get under the canopy — do not spray over the top.
- Apply at the soil level targeting weed stems and root zones.
- Keep product off all shrub foliage, stems, and branches.
- In hedge rows, treat consistently from one end to the other.

FIELD RULES — DO NOT
- Do not broadcast spray over the top of shrubs.
- Do not use soil sterilants or long-residual products under ornamental shrubs.
- Do not apply when wind is moving product into shrub canopy.

RECORD-KEEPING NOTES
Document shrub species (if identifiable), product used, application method, and confirmation of no foliage contact. Note any shrubs that were avoided and why.',
  ],

  // -------------------------------------------------------------------------
  // TID 1204 — Parking Lot
  // -------------------------------------------------------------------------
  1204 => [
    'public' => 'Weeds in parking lots communicate neglect before a customer ever walks through the door. Cracks, perimeter edges, and curb lines that are overtaken by weeds undermine the professional appearance of any commercial property.

Brookstone Outdoors provides comprehensive parking lot weed control for commercial and multi-unit residential properties, covering crack treatment, perimeter edges, curb lines, and unpaved margins — everything that affects how your lot looks from the street.

We schedule parking lot treatments during low-traffic periods wherever possible, minimizing disruption to your tenants, customers, and operations. We coordinate with your property contact to ensure the timing works for your business.

Environmental responsibility is built into every parking lot application. We identify storm drain inlet locations before starting and apply product in targeted, low-volume applications that minimize runoff toward stormwater infrastructure.

A clean, weed-free parking lot is a detail your customers and tenants notice — even if they can\'t articulate why. Brookstone Outdoors keeps your commercial exterior looking maintained, professional, and ready for business.',

    'teammate' => 'PARKING LOT — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Herbicide application to weed growth in and around asphalt or concrete parking lots — cracks, expansion joints, perimeter edges, curb lines, and any adjacent unpaved margins. Typically a commercial or multi-unit residential service location.

UNIQUE CONSIDERATIONS
- Parking lots have high vehicle and foot traffic — application timing relative to traffic flow is important for both safety and product effectiveness.
- Asphalt and concrete surfaces drain to storm drains — product selection and application rate matter for environmental compliance.
- Perimeter edges often transition directly to lawn, beds, or natural areas — drift and runoff control at these edges is critical.
- Some herbicide formulations and carriers can discolor or damage painted striping or specialty coatings.
- Commercial properties may require advance notice to clients or tenants before treatment.

PRODUCT RESTRICTIONS
- Non-selective herbicides are standard for parking lot crack and perimeter treatment.
- Soil sterilant products are appropriate for fully paved lots with no adjacent landscape — do not use where edges transition to lawn, beds, or trees within root zone distance.
- Do not use oil-based carriers on asphalt — they can soften asphalt surfaces and create slip hazards.
- Avoid high-volume application that creates runoff toward storm drains.

ENVIRONMENTAL & LEGAL FLAGS
- Storm drain inlets in parking lots connect directly to municipal stormwater — regulated under the Clean Water Act. Minimize runoff by using targeted, low-volume applications.
- Do not spray directly into or immediately adjacent to storm drain openings.
- If the lot borders a natural area, riparian zone, or retention pond — apply buffer rules from the Retention Pond term to that edge.
- Commercial properties may have environmental compliance requirements beyond standard label compliance — ask your supervisor if uncertain.

FIELD RULES — DO
- Identify storm drain locations before starting.
- Confirm traffic flow — apply during low-traffic periods where possible.
- Notify the account contact before treatment if required by the account.
- Use targeted application at cracks and perimeter edges — minimize surface runoff.
- Keep product away from storm drain openings.

FIELD RULES — DO NOT
- Do not use oil-based carriers on asphalt surfaces.
- Do not apply soil sterilants where the lot edges transition to landscape or trees.
- Do not spray in conditions that will create runoff toward storm drains.

RECORD-KEEPING NOTES
Document property name, lot type (asphalt/concrete), approximate area treated, product used, storm drain proximity, and traffic conditions at time of application. Note any edges treated differently due to adjacent landscape.',
  ],

  // -------------------------------------------------------------------------
  // TID 1771 — Parking Lot Cracks
  // -------------------------------------------------------------------------
  1771 => [
    'public' => 'Cracks and expansion joints in parking lots are the entry point for the weeds that eventually make your entire lot look unmaintained. Early, targeted crack treatment stops weed establishment before it becomes a major visual problem.

Brookstone Outdoors provides precision crack-and-joint herbicide treatment for asphalt and concrete parking lots using dedicated crack applicator tips that put product directly into the joint — not on the surrounding impermeable surface where it can run off.

Our applicators understand the environmental responsibility that comes with working on impermeable surfaces that drain to stormwater infrastructure. We apply at minimum effective volumes, time applications away from rain events, and give storm drain inlets appropriate clearance.

Weeds in parking lot cracks often have surprisingly deep and established root systems. We select products with appropriate residual activity to prevent regrowth, and we schedule follow-up treatments to maintain season-long control.

Clean, weed-free joints and cracks are a small detail that makes a big difference in how your commercial property presents to customers, tenants, and visitors. Brookstone Outdoors delivers that detail consistently.',

    'teammate' => 'PARKING LOT CRACKS — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Targeted herbicide application specifically to cracks and expansion joints in asphalt or concrete parking lots. This is precision crack treatment — not general parking lot perimeter or area treatment.

UNIQUE CONSIDERATIONS
- This is the same location category as Parking Lot, but with a narrower focus — crack-and-joint treatment only. Review the Parking Lot term for broader context.
- Crack treatment on impermeable surfaces means every drop of product that misses the crack sits on the surface and runs off — precision matters more here than almost any other location.
- Vehicle traffic can spread wet product across the lot surface — timing relative to traffic flow is important.
- Expansion joints in concrete lots are wider and deeper than asphalt cracks — they may require different application volume.
- Some parking lot cracks are structural — if weeds have lifted or displaced pavement, note that for the property contact.

PRODUCT RESTRICTIONS
- Non-selective herbicide with residual activity is standard for parking lot cracks.
- Use a crack-and-crevice or pinpoint applicator tip — no flat fan on crack treatment.
- Apply at minimum effective volume — excess product on impermeable surfaces runs off.
- Do not use oil-based carriers on asphalt surfaces.

ENVIRONMENTAL & LEGAL FLAGS
- All parking lot crack treatment environmental rules from the Parking Lot term apply here.
- Storm drain proximity is the primary environmental concern — crack treatment runoff on impermeable surfaces travels directly to storm drains.
- Do not apply immediately before forecast rain.

FIELD RULES — DO
- Use a crack-and-crevice or pinpoint applicator tip.
- Apply directly into the crack at low volume.
- Identify storm drain locations before starting.
- Time application for low-traffic periods.
- Note any structural pavement damage caused by weed growth.

FIELD RULES — DO NOT
- Do not use a flat fan tip for crack treatment.
- Do not apply excess product that pools on the surface.
- Do not spray before forecast rain.
- Do not apply while vehicle traffic is actively crossing the treatment area.

RECORD-KEEPING NOTES
Document lot surface type (asphalt/concrete), application method (crack tip), product and rate, storm drain proximity, and weather conditions. Note any structural damage observed.',
  ],

  // -------------------------------------------------------------------------
  // TID 1163 — Driveway
  // -------------------------------------------------------------------------
  1163 => [
    'public' => 'Your driveway is the first thing visitors see when they arrive. Weeds growing through cracks, along edges, and in expansion joints create an impression of neglect — even when the rest of your property is immaculate.

Brookstone Outdoors provides targeted driveway weed control for concrete, asphalt, gravel, and paver driveways, using application methods matched to each surface type and the landscape areas that border them.

Driveways bordered by lawn require particular care — our applicators use directed application to keep product on the driveway surface and out of your turf. For driveways bordered by landscape beds, we assess root zones and select products that won\'t migrate into ornamental plantings.

We also consider drainage when treating driveways. Product applied to a driveway that slopes toward a garden bed or storm drain requires different timing and volume than a flat, isolated surface. We account for these details on every job.

A clean driveway sets the tone for your entire property. Brookstone Outdoors keeps that first impression working for you.',

    'teammate' => 'DRIVEWAY — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Herbicide application to weed growth on and along residential or commercial driveways — concrete, asphalt, gravel, or paver surfaces used for vehicle traffic and parking.

UNIQUE CONSIDERATIONS
- Driveways are high-visibility surfaces — treatment results (and mistakes) are immediately obvious to the client and neighbors.
- Driveways bordered by lawn are the most common source of non-selective herbicide damage to turf — one careless pass along the edge can kill a strip of grass.
- Concrete and asphalt driveways are impermeable — product applied to the surface stays on the surface until it runs off or dries. Drainage direction matters.
- Gravel driveways are permeable — see On Gravel term for additional considerations.
- Paver driveways have joints that behave like cracks — targeted joint treatment is often more appropriate than broadcast.
- Driveways often slope toward the street, garage, or landscape — apply with awareness of where drainage goes.

PRODUCT RESTRICTIONS
- Non-selective herbicides are standard for driveway crack and edge treatment.
- Soil sterilant or long-residual products are appropriate only on fully paved driveways isolated from lawn and beds.
- Do not use oil-based carriers on asphalt driveways.
- For driveways bordered by lawn within 6 inches, use a shielded applicator or pinpoint tip at the edge — no flat fan.

ENVIRONMENTAL & LEGAL FLAGS
- Driveways that drain to the street connect to the municipal storm system — minimize runoff.
- Do not apply before forecast rain.
- Driveways adjacent to vegetable gardens or edible plantings — confirm product label permits use near food crops.

FIELD RULES — DO
- Identify what borders the driveway on both sides before starting.
- Use a directed wand for edge treatment — shield adjacent turf.
- For crack treatment, use a pinpoint or crack tip.
- Note drainage direction — apply away from sensitive areas.
- Allow product to dry before vehicle traffic resumes.

FIELD RULES — DO NOT
- Do not broadcast spray driveway edges bordered by lawn — directed application only.
- Do not use soil sterilants where the driveway borders lawn, beds, or trees.
- Do not apply in wet conditions — product on hard surfaces creates vehicle traction issues.

RECORD-KEEPING NOTES
Document driveway surface type, application method, adjacent land use on both sides, drainage direction, and weather conditions. Note any turf edges that required shielding.',
  ],

  // -------------------------------------------------------------------------
  // TID 1164 — Roadside
  // -------------------------------------------------------------------------
  1164 => [
    'public' => 'Roadside vegetation management is a specialized service that requires awareness of traffic safety, property boundaries, right-of-way regulations, and environmental buffers that don\'t apply to typical residential spraying.

Brookstone Outdoors provides roadside weed control for private property owners whose land borders public roads, as well as for commercial properties with road-facing frontage. We work on your property — not in the public right-of-way — unless specifically authorized.

Our applicators are trained to work safely near traffic and to apply product in a way that minimizes drift toward the road surface and adjacent properties. We time applications for conditions that keep product on target.

Roadside properties often border irrigation ditches, drainage swales, and other water conveyance features that require buffer zone awareness. We identify these features during our property walk and apply appropriate setbacks.

Clean, managed roadside frontage protects your property value, reduces fire risk from dry weed growth, and demonstrates responsible land stewardship to your community and neighbors.',

    'teammate' => 'ROADSIDE — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Herbicide application along road-facing property edges, shoulders, and frontage on private property. Distinct from CDOT or county right-of-way work — we treat the client\'s property, not the public road margin, unless specifically authorized.

UNIQUE CONSIDERATIONS
- Traffic safety is a primary concern — you are working near moving vehicles. Park safely, use high-visibility clothing, and maintain awareness at all times.
- Property lines along roads are often ambiguous — confirm where the client\'s property ends and the public right-of-way begins before spraying.
- Roadside areas often border irrigation ditches, drainage swales, and culverts — all of which have environmental buffer requirements.
- Dust and vehicle turbulence from passing traffic can carry drift further than expected — account for this when assessing wind conditions.
- Roadside weeds in Western Colorado include state-listed noxious weeds that have specific reporting and treatment requirements.

PRODUCT RESTRICTIONS
- Non-selective herbicides are standard for roadside weed control on non-crop areas.
- Soil sterilant or long-residual products require extra care along roads — drainage from the treated area may reach ditches or adjacent properties.
- If treating near an irrigation ditch, aquatic buffer rules apply — see Retention Pond term.
- Products must be labeled for the specific roadside vegetation present.

ENVIRONMENTAL & LEGAL FLAGS
- Do not spray in the public right-of-way without explicit authorization from the relevant authority (CDOT, county, municipality).
- Colorado Noxious Weed Act may require reporting and specific treatment of listed species found during roadside work — know the list.
- Irrigation ditches and drainage features along roads are regulated — standard aquatic buffers apply.
- Drift toward the road surface or across the road to adjacent properties is a liability event.

FIELD RULES — DO
- Park safely and wear high-visibility clothing.
- Confirm property line before spraying — stay on the client\'s property.
- Walk the treatment area and identify ditches, culverts, and drainage features.
- Check wind — drift toward the road or across the road is not acceptable.
- Identify any noxious weeds and note them in your records.

FIELD RULES — DO NOT
- Do not spray in the public right-of-way without authorization.
- Do not spray in high-wind conditions near roads — drift risk is amplified by vehicle turbulence.
- Do not apply near irrigation ditches without observing aquatic buffer rules.

RECORD-KEEPING NOTES
Document property location, treatment area extent, road name, product used, wind conditions, and proximity to ditches or drainage features. Note any noxious weed species observed.',
  ],

  // -------------------------------------------------------------------------
  // TID 1165 — Vacant Lot
  // -------------------------------------------------------------------------
  1165 => [
    'public' => 'Vacant lots left untreated become weed seed factories that affect every neighboring property. Proactive weed management on vacant land protects your investment, maintains community standards, and reduces the cost of site preparation when you\'re ready to build or sell.

Brookstone Outdoors provides vacant lot weed control for property owners, developers, and property managers who need consistent vegetation management without a full landscaping commitment.

We assess each lot individually — soil type, weed species, drainage patterns, and what borders the property all influence product selection and application strategy. A vacant lot bordered by residential homes requires a different approach than one bordered by open agricultural land.

For lots awaiting development, we can implement a weed management schedule that keeps the site presentable and code-compliant while preserving soil health for future landscaping or construction.

Whether you\'re holding property for future use, preparing a lot for sale, or maintaining compliance with municipal weed ordinances, Brookstone Outdoors provides reliable, professional vacant lot management you can count on.',

    'teammate' => 'VACANT LOT — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Herbicide application for general weed suppression on an unoccupied, undeveloped, or minimally improved lot. May include the entire lot surface or specific zones.

UNIQUE CONSIDERATIONS
- Vacant lots can be large — accurate area measurement is essential for proper application rate. Do not estimate; measure or use GPS.
- Vacant lots often have no irrigation, no landscape, and no turf — the treatment objective is different from maintained properties.
- Neighboring properties may be residential with maintained landscapes — drift and runoff control at the property edges is critical.
- Vacant lots may have standing water, drainage features, or seasonal wetland conditions — assess before applying.
- Municipal weed ordinances may apply — some jurisdictions require vacant lots to be maintained below specific weed height thresholds.
- Wildlife, nesting birds, and pollinator habitat may be present — assess before treating and note any protected features.

PRODUCT RESTRICTIONS
- Non-selective herbicides with residual activity are standard for vacant lot bare-ground control.
- Soil sterilant products may be appropriate for lots with no future planting planned — confirm with supervisor and client before using.
- If the lot borders residential landscape, use products with limited lateral movement at those edges.
- For lots near agricultural land, confirm product label permits application adjacent to crop areas.

ENVIRONMENTAL & LEGAL FLAGS
- Vacant lots in municipalities may be subject to weed ordinance enforcement — treatment may be legally required.
- Standing water or seasonal drainage on the lot may constitute a wetland — do not treat these areas without supervisor guidance.
- Property boundary awareness is mandatory — do not treat onto adjacent parcels.
- Noxious weed presence must be noted — Colorado law may require specific treatment.

FIELD RULES — DO
- Measure or GPS the treatment area before mixing product.
- Walk the perimeter and identify all adjacent land uses.
- Assess for standing water, drainage, and wildlife presence.
- Apply at label rate — do not increase rate on vacant lots just because there are no desirable plants.
- Note any noxious weed species present.

FIELD RULES — DO NOT
- Do not treat standing water or drainage features without supervisor guidance.
- Do not apply soil sterilants without supervisor and client approval.
- Do not spray across property boundaries.
- Do not apply in wind conditions that could carry product to adjacent residential properties.

RECORD-KEEPING NOTES
Document lot location, approximate area in square feet or acres, product and rate, adjacent land use on all sides, presence of standing water or drainage features, and any noxious weed species observed. Note municipal ordinance compliance if applicable.',
  ],

  // -------------------------------------------------------------------------
  // TID 1166 — Pasture
  // -------------------------------------------------------------------------
  1166 => [
    'public' => 'Pasture weed management directly affects the health and productivity of the livestock that depend on it. The wrong weed in a pasture isn\'t just an aesthetic problem — it can be toxic, crowd out nutritious forage, and reduce carrying capacity.

Brookstone Outdoors provides pasture weed control services for horse, cattle, and small livestock operations in Western Colorado. We use only products labeled for pasture use with the specific livestock present, and we communicate grazing restrictions clearly on every job.

Our licensed applicators identify target weed species before treating, distinguishing between broadleaf pasture weeds, noxious weeds requiring mandatory treatment, and desirable forage species that should be protected.

We work with your grazing schedule to time applications for maximum effectiveness with minimum disruption. When grazing restrictions are required, we coordinate with you before the application — never after.

Healthy pasture starts with professional weed management that respects the animals, the forage, and the land. Brookstone Outdoors brings that level of care to every pasture we treat.',

    'teammate' => 'PASTURE — TEAMMATE INSTRUCTIONS

WHAT THIS IS
Herbicide application to weed growth in active or recently active pasture — land used for grazing by horses, cattle, sheep, goats, or other livestock. This is one of the highest-responsibility spraying locations due to direct animal contact with treated vegetation and soil.

UNIQUE CONSIDERATIONS
- Livestock will eat treated vegetation unless physically excluded — product selection must account for animal ingestion.
- Different livestock species have different sensitivities — products safe for cattle pasture may not be safe for horse pasture.
- Grazing restrictions (days animals must be removed from treated area) vary by product — know the restriction before you spray.
- Pasture weeds include species that are toxic to livestock (larkspur, locoweed, death camas) — killing these weeds can temporarily increase toxicity risk if animals consume wilting treated plants before they fully die.
- Some pasture herbicides can contaminate hay if treated forage is cut and baled — note this if the pasture is also used for hay production.

PRODUCT RESTRICTIONS
- Use only products explicitly labeled for pasture use with the specific livestock species present.
- Know the grazing restriction interval for the product and livestock combination.
- Products containing aminopyralid or clopyralid persist in manure and can damage garden crops if composted manure is used — note this for clients who compost.
- Do not use non-selective herbicides in active pasture — they will kill the forage.

ENVIRONMENTAL & LEGAL FLAGS
- Colorado CDA requires documentation of livestock species, grazing restriction interval, and product used for all pasture applications.
- Noxious weed management in pasture may be required by county weed district — note species and report if required.
- Pasture near waterways requires aquatic buffer compliance — see Retention Pond term.
- Organic livestock operations cannot have synthetic herbicides applied to their pasture — confirm with client if there is any organic certification.

FIELD RULES — DO
- Confirm livestock species present BEFORE selecting product.
- Communicate grazing restriction interval to the client BEFORE spraying.
- Walk the pasture and identify target weed species, desirable forage, and any toxic weed species.
- If toxic weeds are present, warn the client about temporary increased toxicity of wilting treated plants.
- Coordinate with the client\'s grazing schedule.

FIELD RULES — DO NOT
- Do not spray pasture without confirming livestock species and grazing restrictions.
- Do not assume "cattle safe" means "horse safe" — they are different.
- Do not spray near stock water tanks, troughs, or ponds without buffer distance.
- Do not spray if livestock cannot be excluded for the required restriction period.

RECORD-KEEPING NOTES
Document livestock species present, grazing restriction interval, product and rate, approximate area treated, target weed species, and any toxic weed species observed. Note client communication of grazing restrictions and coordination with grazing schedule.',
  ],

  // -------------------------------------------------------------------------
  // TID 1167 — Entire Area
  // -------------------------------------------------------------------------
  1167 => [
    'public' => 'Some properties need complete weed control across all outdoor surfaces — lawn, beds, gravel, hardscape, and perimeter edges — in a single coordinated service visit.

Brookstone Outdoors provides whole-property weed management for residential and commercial clients who want consistent results across every surface type without juggling multiple service visits and providers.

An entire-area treatment isn\'t one product applied everywhere — it\'s a property-specific plan that uses the right product for each surface, applied by a licensed professional who understands how each zone connects to the others.

We start with a property walk to identify every treatment zone — lawn areas requiring selective herbicide, beds requiring directed application, hardscape requiring crack treatment, and gravel requiring appropriate residual products. Each zone gets what it needs.

The result is a property that looks uniformly maintained from every angle. No missed corners, no untreated edges, no gaps between service types. Brookstone Outdoors manages the whole picture.',

    'teammate' => 'ENTIRE AREA — TEAMMATE INSTRUCTIONS

WHAT THIS IS
A whole-property treatment covering all outdoor surface types — this is typically a combination of multiple spraying locations treated in a single service visit.

UNIQUE CONSIDERATIONS
- Entire Area is not a product decision — it is a scope decision. You will need to apply different products and methods to different zones within the property.
- Pre-plan the treatment by walking the property and identifying distinct zones: lawn, beds, gravel, hardscape, perimeter edges, tree rings, etc.
- Product changes between zones require equipment rinsing or dedicated applicators — do not cross-contaminate.
- This is the most time-intensive treatment scope — plan for a longer service visit and communicate timeline to the client.
- Transition zones between surface types are the highest-risk areas for product misapplication — e.g., lawn edge meeting a bed, gravel meeting lawn, etc.

PRODUCT RESTRICTIONS
- Each zone within the property requires its own product selection per that zone\'s term instructions — see Lawn, Landscape Beds, On Gravel, Driveway, Tree Rings, etc.
- Do not use a single non-selective product across the entire property unless every surface is hardscape or bare ground.
- Rinse equipment between product changes — especially when switching between selective and non-selective herbicides.

ENVIRONMENTAL & LEGAL FLAGS
- All environmental rules from the individual zone terms apply — Entire Area does not override or simplify any location-specific restrictions.
- Colorado CDA requires spray records to document each product and treatment area separately, even when applied on a single visit.

FIELD RULES — DO
- Walk the property first — identify all zones and plan your product sequence.
- Treat zones in logical order: start with selective products (lawn), then directed products (beds, tree rings), then non-selective (hardscape, gravel).
- Rinse equipment between product changes.
- Document each zone\'s treatment separately in your spray record.
- Communicate the full re-entry interval for the entire property (longest REI of any product used).

FIELD RULES — DO NOT
- Do not apply a single product to the entire property unless directed by supervisor.
- Do not skip the property walk — Entire Area requires pre-planning.
- Do not forget to rinse equipment between zone changes.

RECORD-KEEPING NOTES
Document each zone treated, product used per zone, application method, and any zone-specific notes. The spray record for an Entire Area job should effectively be multiple records — one per zone. Note overall property square footage and breakdown by zone if possible.',
  ],

  // -------------------------------------------------------------------------
  // TID 1219 — Other (See Description)
  // -------------------------------------------------------------------------
  1219 => [
    'public' => 'Not every spraying location fits into a standard category. Properties with unique features — retention ponds, compost yards, construction staging areas, solar panel arrays, cemetery plots, athletic fields, or other specialized zones — require customized weed management.

Brookstone Outdoors handles non-standard spraying locations with the same professionalism and environmental responsibility as our standard services. We assess the site, identify constraints, select appropriate products, and communicate clearly with the property owner.

When you see this location on a work order, the Description field will provide specific details about the treatment area and any special requirements. Our applicators review the description before arriving and contact the office if anything is unclear.

Every property is different, and Brookstone Outdoors is built to handle the details that make yours unique.',

    'teammate' => 'OTHER (SEE DESCRIPTION) — TEAMMATE INSTRUCTIONS

WHAT THIS IS
A spraying location that does not fit any standard category. The Description field on the work order provides specific details about what and where to treat.

UNIQUE CONSIDERATIONS
- This is a catch-all location — you must read the Description field before starting work.
- The Description should tell you what the area is, what the treatment target is, and any specific constraints.
- If the Description is vague, empty, or unclear — call the office before proceeding.
- Common "Other" locations include: retention ponds, compost areas, construction staging, solar arrays, cemetery plots, athletic fields, storage yards.
- Each of these has unique product, safety, and environmental considerations that may not be covered by standard location terms.

PRODUCT RESTRICTIONS
- Product selection depends entirely on what the location actually is — there is no default.
- If the location involves water features, livestock, food production, public spaces, or athletic fields, specific product restrictions apply — confirm with supervisor.

ENVIRONMENTAL & LEGAL FLAGS
- Unknown until you identify the actual location — assess on arrival.
- If the site involves regulated features (water, wetlands, livestock, public access), apply the relevant location term\'s environmental rules.

FIELD RULES — DO
- Read the work order Description field before leaving the shop.
- Call the office if the Description is unclear, empty, or contradicts what you see on site.
- Assess the site on arrival — identify any features that require special consideration.
- Apply product only to the area and targets described in the work order.

FIELD RULES — DO NOT
- Do not treat an "Other" location without reading the Description first.
- Do not assume the location is low-risk because it\'s labeled "Other."
- Do not proceed if the Description is empty — call the office.

RECORD-KEEPING NOTES
Document actual location type, product used, area treated, and any features that required special handling. Note if the Description was adequate or needed clarification, and what clarification was obtained.',
  ],

];

// ── Execute ──────────────────────────────────────────────────────────────────
$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$updated = 0;
$errors = 0;

foreach ($terms as $tid => $data) {
  $term = $term_storage->load($tid);
  if (!$term) {
    echo "WARNING: TID $tid not found — skipped.\n";
    $errors++;
    continue;
  }

  // Set public description (field_description — text_long w/ format).
  if ($term->hasField('field_description')) {
    $term->set('field_description', [
      'value' => $data['public'],
      'format' => 'full_html',
    ]);
  }

  // Set teammate instructions (field_teammate_description — text_long w/ format).
  if ($term->hasField('field_teammate_description')) {
    $term->set('field_teammate_description', [
      'value' => $data['teammate'],
      'format' => 'full_html',
    ]);
  }

  try {
    $term->save();
    echo "OK: TID $tid — {$term->label()}\n";
    $updated++;
  }
  catch (\Throwable $e) {
    echo "ERROR: TID $tid — {$e->getMessage()}\n";
    $errors++;
  }
}

echo "\nDone. Updated: $updated, Errors: $errors\n";
