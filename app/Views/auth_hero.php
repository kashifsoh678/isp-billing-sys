<?php
/**
 * Developed by Kashif Sohail (Kashif  Scripts)
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Auth hero - the drawn network panel shared by login / signup / password pages.
 * Set $hero_headline / $hero_lede before including to change the copy.
 */
$login_bg      = isset($login_bg) ? $login_bg : trim((string)getSetting('login_bg_image', ''));
$hero_headline = isset($hero_headline) ? $hero_headline : 'Your whole network,<br>on one screen.';
$hero_lede     = isset($hero_lede) ? $hero_lede : 'Subscribers, packages, invoices, field complaints and collections &mdash; connected end to end, from the tower to the last house on the street.';
?>
<aside class="auth-visual<?php echo $login_bg ? ' has-photo' : ''; ?>"<?php echo $login_bg ? ' style="background-image:url(\'' . htmlspecialchars($login_bg, ENT_QUOTES) . '\')"' : ''; ?>>
            <svg class="nv-art" viewBox="0 0 720 900" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
                <defs>
                    <linearGradient id="nvSky" x1="0" y1="0" x2="1" y2="1">
                        <stop class="nv-sky-a" offset="0"></stop>
                        <stop class="nv-sky-b" offset="1"></stop>
                    </linearGradient>
                    <radialGradient id="nvGlow" cx=".5" cy=".5" r=".5">
                        <stop class="nv-glow-a" offset="0"></stop>
                        <stop class="nv-glow-b" offset="1"></stop>
                    </radialGradient>
                    <pattern id="nvGrid" width="45" height="45" patternUnits="userSpaceOnUse">
                        <path class="nv-grid" d="M45 0H0v45"></path>
                    </pattern>
                </defs>

                <rect width="720" height="900" fill="url(#nvSky)"></rect>
                <rect width="720" height="900" fill="url(#nvGrid)"></rect>
                <circle cx="235" cy="330" r="300" fill="url(#nvGlow)"></circle>

                <!-- backbone fibre + the data running through it -->
                <g>
                    <path class="nv-line" d="M-40 250C120 210 230 330 360 300s250-120 440-64"></path>
                    <path class="nv-line" d="M-40 415C140 392 215 470 385 452s265-92 415-28"></path>
                    <path class="nv-line" d="M235 560C300 640 420 616 520 668s130 84 240 104"></path>
                    <path class="nv-line" d="M235 560C170 640 130 700 118 762"></path>
                    <path class="nv-flow" d="M-40 250C120 210 230 330 360 300s250-120 440-64"></path>
                    <path class="nv-flow nv-flow-b" d="M-40 415C140 392 215 470 385 452s265-92 415-28"></path>
                    <path class="nv-flow nv-flow-c" d="M235 560C300 640 420 616 520 668s130 84 240 104"></path>
                </g>

                <!-- tower + signal -->
                <g>
                    <path class="nv-arc" d="M262 262a46 46 0 0 1 0 96"></path>
                    <path class="nv-arc nv-arc-b" d="M287 238a76 76 0 0 1 0 144"></path>
                    <path class="nv-arc nv-arc-c" d="M312 214a106 106 0 0 1 0 192"></path>
                    <path class="nv-arc" d="M208 262a46 46 0 0 0 0 96"></path>
                    <path class="nv-arc nv-arc-b" d="M183 238a76 76 0 0 0 0 144"></path>
                    <path class="nv-arc nv-arc-c" d="M158 214a106 106 0 0 0 0 192"></path>

                    <path class="nv-tower" d="M212 560 235 268 258 560M218 490h34M222 420h26M226 350h18M212 560h46"></path>
                    <path class="nv-tower" d="M218 490 235 420 252 490M222 420 235 350 248 420"></path>
                    <path class="nv-tower" d="M235 268v-26M223 252h24"></path>
                    <circle class="nv-beacon" cx="235" cy="236" r="6"></circle>
                </g>

                <!-- subscriber side: homes, an ONT and the drop lines -->
                <g>
                    <rect class="nv-ground" y="836" width="720" height="64"></rect>

                    <path class="nv-bld" d="M392 838V742h74v96z"></path>
                    <path class="nv-bld" d="M486 838V706h58v132z"></path>
                    <path class="nv-bld" d="M564 838V766h62v72z"></path>
                    <path class="nv-bld" d="M646 838V730h56v108z"></path>
                    <path class="nv-bld" d="M292 838v-64l40-30 40 30v64z"></path>

                    <g class="nv-win">
                        <rect x="404" y="758" width="12" height="12"></rect>
                        <rect x="428" y="758" width="12" height="12"></rect>
                        <rect x="404" y="788" width="12" height="12"></rect>
                        <rect x="442" y="788" width="12" height="12"></rect>
                        <rect x="498" y="726" width="12" height="12"></rect>
                        <rect x="522" y="726" width="12" height="12"></rect>
                        <rect x="498" y="758" width="12" height="12"></rect>
                        <rect x="522" y="790" width="12" height="12"></rect>
                        <rect x="578" y="786" width="12" height="12"></rect>
                        <rect x="602" y="786" width="12" height="12"></rect>
                        <rect x="658" y="750" width="12" height="12"></rect>
                        <rect x="682" y="750" width="12" height="12"></rect>
                        <rect x="658" y="784" width="12" height="12"></rect>
                        <rect x="316" y="790" width="14" height="14"></rect>
                        <rect x="340" y="790" width="14" height="14"></rect>
                    </g>

                    <path class="nv-drop" d="M429 742v-38M515 706v-34M595 766v-40M674 730v-36M332 748v-30"></path>
                    <circle class="nv-node" cx="429" cy="700" r="5"></circle>
                    <circle class="nv-node" cx="515" cy="668" r="5"></circle>
                    <circle class="nv-node" cx="595" cy="722" r="5"></circle>
                    <circle class="nv-node" cx="674" cy="690" r="5"></circle>
                    <circle class="nv-node" cx="332" cy="714" r="5"></circle>
                    <circle class="nv-ring" cx="515" cy="668" r="5"></circle>
                    <circle class="nv-ring nv-ring-b" cx="674" cy="690" r="5"></circle>

                    <!-- roof dish -->
                    <path class="nv-tower" d="M332 718v-16M326 702h12"></path>
                    <path class="nv-dish" d="M322 696a14 14 0 0 1 22-11"></path>

                    <!-- ONT / router with wifi -->
                    <rect class="nv-ont" x="86" y="762" width="64" height="24" rx="6"></rect>
                    <path class="nv-tower" d="M104 762v-16M118 762v-24M132 762v-16"></path>
                    <circle class="nv-node" cx="100" cy="774" r="3"></circle>
                    <circle class="nv-node" cx="112" cy="774" r="3"></circle>
                    <path class="nv-arc nv-arc-b" d="M96 736a34 34 0 0 1 44 0"></path>
                    <path class="nv-arc nv-arc-c" d="M84 716a56 56 0 0 1 68 0"></path>
                </g>
            </svg>

            <div class="auth-visual-inner">
                <div class="auth-brand">
                    <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="" class="auth-brand-logo">
                    <span class="auth-brand-name"><?php echo htmlspecialchars($branding['site_name']); ?></span>
                </div>

                <h1 class="auth-headline"><?php echo $hero_headline; ?></h1>
                <p class="auth-lede"><?php echo $hero_lede; ?></p>

                <ul class="auth-points">
                    <li>
                        <span class="auth-point-ico"><i class="fas fa-wifi"></i></span>
                        <span><strong>Packages &amp; auto-billing</strong>Monthly invoices raised on schedule, priced per package</span>
                    </li>
                    <li>
                        <span class="auth-point-ico"><i class="fas fa-headset"></i></span>
                        <span><strong>Complaints &amp; installations</strong>Field jobs tracked from the first call to closure</span>
                    </li>
                    <li>
                        <span class="auth-point-ico"><i class="fas fa-file-invoice-dollar"></i></span>
                        <span><strong>Payments, ledger &amp; POS</strong>Every receipt lands in the customer ledger</span>
                    </li>
                </ul>

                <div class="auth-chips">
                    <span class="auth-chip"><i class="fas fa-tower-broadcast"></i> Areas &amp; coverage</span>
                    <span class="auth-chip"><i class="fas fa-network-wired"></i> Equipment &amp; stock</span>
                    <span class="auth-chip"><i class="fas fa-chart-line"></i> Aging &amp; recovery</span>
                </div>
            </div>
        </aside>
