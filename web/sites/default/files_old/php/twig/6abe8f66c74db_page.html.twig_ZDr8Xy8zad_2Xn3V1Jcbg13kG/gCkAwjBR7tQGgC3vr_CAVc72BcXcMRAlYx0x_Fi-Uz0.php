<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* themes/custom/chirothintracker_subtheme/templates/layout/page.html.twig */
class __TwigTemplate_98765c14af5f1912865cbc128598481c extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
        $this->sandbox = $this->extensions[SandboxExtension::class];
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 48
        $context["nav_classes"] = ((("navbar navbar-expand-md" . (((        // line 49
($context["b5_navbar_schema"] ?? null) != "none")) ? ((" navbar-" . ($context["b5_navbar_schema"] ?? null))) : (" "))) . (((        // line 50
($context["b5_navbar_schema"] ?? null) != "none")) ? ((((($context["b5_navbar_schema"] ?? null) == "dark")) ? (" text-light") : (" text-dark"))) : (" "))) . (((        // line 51
($context["b5_navbar_bg_schema"] ?? null) != "none")) ? ((" bg-" . ($context["b5_navbar_bg_schema"] ?? null))) : (" ")));
        // line 53
        yield "
";
        // line 55
        $context["footer_classes"] = (((" " . (((        // line 56
($context["b5_footer_schema"] ?? null) != "none")) ? ((" footer-" . ($context["b5_footer_schema"] ?? null))) : (" "))) . (((        // line 57
($context["b5_footer_schema"] ?? null) != "none")) ? ((((($context["b5_footer_schema"] ?? null) == "dark")) ? (" text-light") : (" text-dark"))) : (" "))) . (((        // line 58
($context["b5_footer_bg_schema"] ?? null) != "none")) ? ((" bg-" . ($context["b5_footer_bg_schema"] ?? null))) : (" ")));
        // line 60
        yield "
";
        // line 61
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "header_top", [], "any", false, false, true, 61)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 62
            yield "\t";
            if ((($tmp = ($context["logged_in"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 63
                yield "\t\t<div class=\"header-top\">
\t\t\t<div class=\"dashboard-toggle\"></div>
\t\t\t";
                // line 65
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "header_top", [], "any", false, false, true, 65), "html", null, true);
                yield "
\t\t</div>
\t";
            }
        }
        // line 69
        yield "
<header role=\"banner\">
\t";
        // line 71
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "header", [], "any", false, false, true, 71), "html", null, true);
        yield "
\t";
        // line 72
        if ((($tmp =  !($context["logged_in"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 73
            yield "\t\t";
            if (((CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_branding", [], "any", false, false, true, 73) || CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_main", [], "any", false, false, true, 73)) || CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_additional", [], "any", false, false, true, 73))) {
                // line 74
                yield "\t\t\t<nav class=\"";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["nav_classes"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t<div class=\"";
                // line 75
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["b5_top_container"] ?? null), "html", null, true);
                yield " d-flex\">
\t\t\t\t\t";
                // line 76
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_branding", [], "any", false, false, true, 76), "html", null, true);
                yield "

\t\t\t\t\t";
                // line 78
                if ((CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_main", [], "any", false, false, true, 78) || CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_additional", [], "any", false, false, true, 78))) {
                    // line 79
                    yield "\t\t\t\t\t\t<button class=\"navbar-toggler icon collapsed\" type=\"button\" data-bs-toggle=\"collapse\" data-bs-target=\"#navbarSupportedContent\" aria-controls=\"navbarSupportedContent\" aria-expanded=\"false\" aria-label=\"Toggle navigation\">
\t\t\t\t\t\t\t<span class=\"menu-toggle-bar menu-toggle-bar--top\"></span>
\t\t\t\t\t\t\t<span class=\"menu-toggle-bar menu-toggle-bar--middle\"></span>
\t\t\t\t\t\t\t<span class=\"menu-toggle-bar menu-toggle-bar--bottom\"></span>
\t\t\t\t\t\t</button>

\t\t\t\t\t\t<div class=\"collapse navbar-collapse justify-content-md-end\" id=\"navbarSupportedContent\">
\t\t\t\t\t\t\t";
                    // line 86
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_main", [], "any", false, false, true, 86), "html", null, true);
                    yield "
\t\t\t\t\t\t\t";
                    // line 87
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_additional", [], "any", false, false, true, 87), "html", null, true);
                    yield "
\t\t\t\t\t\t</div>
\t\t\t\t\t";
                }
                // line 90
                yield "\t\t\t\t</div>
\t\t\t</nav>
\t\t";
            }
            // line 93
            yield "\t";
        }
        // line 94
        yield "
</header>

<main role=\"main\">
\t<a id=\"main-content\" tabindex=\"-1\"></a>
\t";
        // line 100
        yield "
\t";
        // line 102
        $context["sidebar_first_classes"] = (((CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_first", [], "any", false, false, true, 102) && CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_second", [], "any", false, false, true, 102))) ? ("col-12 col-sm-6 col-lg-3") : ("col-12 col-lg-3"));
        // line 104
        yield "
\t";
        // line 106
        $context["sidebar_second_classes"] = (((CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_first", [], "any", false, false, true, 106) && CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_second", [], "any", false, false, true, 106))) ? ("col-12 col-sm-6 col-lg-3") : ("col-12 col-lg-3"));
        // line 108
        yield "
\t";
        // line 110
        $context["content_classes"] = (((CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_first", [], "any", false, false, true, 110) && CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_second", [], "any", false, false, true, 110))) ? ("col-12 col-lg-6") : ((((CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_first", [], "any", false, false, true, 110) || CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_second", [], "any", false, false, true, 110))) ? ("col-12 col-lg-9") : ("col-12"))));
        // line 112
        yield "

\t<div class=\"";
        // line 114
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["b5_top_container"] ?? null), "html", null, true);
        yield "\">
\t\t";
        // line 115
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "breadcrumb", [], "any", false, false, true, 115)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 116
            yield "\t\t\t";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "breadcrumb", [], "any", false, false, true, 116), "html", null, true);
            yield "
\t\t";
        }
        // line 118
        yield "\t\t<div class=\"row g-0\">
\t\t\t<div class=\"website-layout\">
\t\t\t\t";
        // line 120
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_menu", [], "any", false, false, true, 120)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 121
            yield "\t\t\t\t\t";
            if ((($context["logged_in"] ?? null) &&  !($context["is_admin"] ?? null))) {
                // line 122
                yield "\t\t\t\t\t\t<div class=\"sidebar-menu\">
\t\t\t\t\t\t\t";
                // line 123
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_branding", [], "any", false, false, true, 123), "html", null, true);
                yield "

\t\t\t\t\t\t\t";
                // line 125
                if ((CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_main", [], "any", false, false, true, 125) || CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_additional", [], "any", false, false, true, 125))) {
                    // line 126
                    yield "\t\t\t\t\t\t\t\t";
                    // line 129
                    yield "
\t\t\t\t\t\t\t\t<div class=\"collapse navbar-collapse justify-content-md-end\" id=\"navbarSupportedContent\">
\t\t\t\t\t\t\t\t\t";
                    // line 131
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_main", [], "any", false, false, true, 131), "html", null, true);
                    yield "
\t\t\t\t\t\t\t\t\t";
                    // line 132
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "nav_additional", [], "any", false, false, true, 132), "html", null, true);
                    yield "
\t\t\t\t\t\t\t\t</div>
\t\t\t\t\t\t\t";
                }
                // line 135
                yield "\t\t\t\t\t\t\t";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_menu", [], "any", false, false, true, 135), "html", null, true);
                yield "
\t\t\t\t\t\t</div>
\t\t\t\t\t";
            }
            // line 138
            yield "
\t\t\t\t";
        }
        // line 140
        yield "\t\t\t\t";
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_first", [], "any", false, false, true, 140)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 141
            yield "\t\t\t\t\t<div class=\"order-2 order-lg-1 ";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["sidebar_first_classes"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t\t";
            // line 142
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_first", [], "any", false, false, true, 142), "html", null, true);
            yield "
\t\t\t\t\t</div>
\t\t\t\t";
        }
        // line 145
        yield "\t\t\t\t<div class=\"website-content-area\">
\t\t\t\t\t<div class=\"order-1 order-lg-2 ";
        // line 146
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["content_classes"] ?? null), "html", null, true);
        yield "\">
\t\t\t\t\t\t";
        // line 147
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "content", [], "any", false, false, true, 147), "html", null, true);
        yield "
\t\t\t\t\t</div>
\t\t\t\t</div>
\t\t\t</div>
\t\t\t";
        // line 151
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_second", [], "any", false, false, true, 151)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 152
            yield "\t\t\t\t<div class=\"order-3 ";
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["sidebar_second_classes"] ?? null), "html", null, true);
            yield "\">
\t\t\t\t\t";
            // line 153
            yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "sidebar_second", [], "any", false, false, true, 153), "html", null, true);
            yield "
\t\t\t\t</div>
\t\t\t";
        }
        // line 156
        yield "\t\t</div>
\t</div>

</main>

";
        // line 161
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "footer", [], "any", false, false, true, 161)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 162
            yield "\t";
            if ((($tmp =  !($context["logged_in"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 163
                yield "\t\t<footer role=\"contentinfo\" class=\"mt-auto ";
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["footer_classes"] ?? null), "html", null, true);
                yield "\">
\t\t\t<div class=\"";
                // line 164
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["b5_top_container"] ?? null), "html", null, true);
                yield "\">
\t\t\t\t";
                // line 165
                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["page"] ?? null), "footer", [], "any", false, false, true, 165), "html", null, true);
                yield "
\t\t\t</div>
\t\t</footer>
\t";
            }
        }
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["b5_navbar_schema", "b5_navbar_bg_schema", "b5_footer_schema", "b5_footer_bg_schema", "page", "logged_in", "b5_top_container", "is_admin"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "themes/custom/chirothintracker_subtheme/templates/layout/page.html.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  277 => 165,  273 => 164,  268 => 163,  265 => 162,  263 => 161,  256 => 156,  250 => 153,  245 => 152,  243 => 151,  236 => 147,  232 => 146,  229 => 145,  223 => 142,  218 => 141,  215 => 140,  211 => 138,  204 => 135,  198 => 132,  194 => 131,  190 => 129,  188 => 126,  186 => 125,  181 => 123,  178 => 122,  175 => 121,  173 => 120,  169 => 118,  163 => 116,  161 => 115,  157 => 114,  153 => 112,  151 => 110,  148 => 108,  146 => 106,  143 => 104,  141 => 102,  138 => 100,  131 => 94,  128 => 93,  123 => 90,  117 => 87,  113 => 86,  104 => 79,  102 => 78,  97 => 76,  93 => 75,  88 => 74,  85 => 73,  83 => 72,  79 => 71,  75 => 69,  68 => 65,  64 => 63,  61 => 62,  59 => 61,  56 => 60,  54 => 58,  53 => 57,  52 => 56,  51 => 55,  48 => 53,  46 => 51,  45 => 50,  44 => 49,  43 => 48,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "themes/custom/chirothintracker_subtheme/templates/layout/page.html.twig", "/app/web/themes/custom/chirothintracker_subtheme/templates/layout/page.html.twig");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = ["set" => 48, "if" => 61];
        static $filters = ["escape" => 65];
        static $functions = [];

        try {
            $this->sandbox->checkSecurity(
                [0 => "set", 1 => "if"],
                [0 => "escape"],
                [],
                $this->source
            );
        } catch (SecurityError $e) {
            $e->setSourceContext($this->source);

            if ($e instanceof SecurityNotAllowedTagError && isset($tags[$e->getTagName()])) {
                $e->setTemplateLine($tags[$e->getTagName()]);
            } elseif ($e instanceof SecurityNotAllowedFilterError && isset($filters[$e->getFilterName()])) {
                $e->setTemplateLine($filters[$e->getFilterName()]);
            } elseif ($e instanceof SecurityNotAllowedFunctionError && isset($functions[$e->getFunctionName()])) {
                $e->setTemplateLine($functions[$e->getFunctionName()]);
            }

            throw $e;
        }

    }
}
