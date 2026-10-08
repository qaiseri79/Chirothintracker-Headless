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

/* themes/custom/chirothintracker_subtheme/templates/navigation/menu--menu-header-right.html.twig */
class __TwigTemplate_0b97937f97e41ccad95ad179d134e6ed extends Template
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
        // line 21
        $macros["menus"] = $this->macros["menus"] = $this;
        // line 22
        yield "
";
        // line 27
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar($macros["menus"]->getTemplateForMacro("macro_menu_links", $context, 27, $this->getSourceContext())->macro_menu_links(...[($context["items"] ?? null), ($context["attributes"] ?? null), 0]));
        yield "

";
        // line 126
        yield "

";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["_self", "items", "attributes", "menu_level"]);        yield from [];
    }

    // line 29
    public function macro_menu_links($items = null, $attributes = null, $menu_level = null, ...$varargs): string|Markup
    {
        $macros = $this->macros;
        $context = [
            "items" => $items,
            "attributes" => $attributes,
            "menu_level" => $menu_level,
            "varargs" => $varargs,
        ] + $this->env->getGlobals();

        $blocks = [];

        return ('' === $tmp = \Twig\Extension\CoreExtension::captureOutput((function () use (&$context, $macros, $blocks) {
            // line 30
            yield "  ";
            $macros["menus"] = $this;
            // line 31
            yield "  ";
            if ((($tmp = ($context["items"] ?? null)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 32
                yield "    ";
                if ((($context["menu_level"] ?? null) == 0)) {
                    // line 33
                    yield "      <ul";
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, ($context["attributes"] ?? null), "addClass", [["nav navbar-nav"]], "method", false, false, true, 33), "html", null, true);
                    yield ">
    ";
                } else {
                    // line 35
                    yield "      <ul>
    ";
                }
                // line 37
                yield "    ";
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(($context["items"] ?? null));
                foreach ($context['_seq'] as $context["_key"] => $context["item"]) {
                    // line 38
                    yield "      ";
                    // line 39
                    $context["classes_link"] = ["nav-link", (((($tmp = CoreExtension::getAttribute($this->env, $this->source,                     // line 41
$context["item"], "is_expanded", [], "any", false, false, true, 41)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("dropdown-toggle") : ("")), (((($tmp = CoreExtension::getAttribute($this->env, $this->source,                     // line 42
$context["item"], "is_collapsed", [], "any", false, false, true, 42)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("dropdown-toggle") : ("")), (((($tmp = CoreExtension::getAttribute($this->env, $this->source,                     // line 43
$context["item"], "in_active_trail", [], "any", false, false, true, 43)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) ? ("active") : (""))];
                    // line 46
                    yield "
      ";
                    // line 47
                    $context["title_text"] = Twig\Extension\CoreExtension::striptags(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "title", [], "any", false, false, true, 47));
                    // line 48
                    yield "      ";
                    $context["title"] = Twig\Extension\CoreExtension::trim(($context["title_text"] ?? null));
                    // line 49
                    yield "
      ";
                    // line 51
                    yield "      ";
                    $context["doctor_message_count"] = (($_v0 = CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 51)) && is_array($_v0) || $_v0 instanceof ArrayAccess && in_array($_v0::class, CoreExtension::ARRAY_LIKE_CLASSES, true) ? ($_v0["doctor-message-count"] ?? null) : CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 51), "doctor-message-count", [], "array", false, false, true, 51));
                    // line 52
                    yield "      ";
                    $context["patient_message_count"] = (($_v1 = CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 52)) && is_array($_v1) || $_v1 instanceof ArrayAccess && in_array($_v1::class, CoreExtension::ARRAY_LIKE_CLASSES, true) ? ($_v1["patient-message-count"] ?? null) : CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 52), "patient-message-count", [], "array", false, false, true, 52));
                    // line 53
                    yield "      ";
                    $context["intake_count"] = (($_v2 = CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 53)) && is_array($_v2) || $_v2 instanceof ArrayAccess && in_array($_v2::class, CoreExtension::ARRAY_LIKE_CLASSES, true) ? ($_v2["intake-count"] ?? null) : CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 53), "intake-count", [], "array", false, false, true, 53));
                    // line 54
                    yield "      ";
                    $context["task_count"] = (($_v3 = CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 54)) && is_array($_v3) || $_v3 instanceof ArrayAccess && in_array($_v3::class, CoreExtension::ARRAY_LIKE_CLASSES, true) ? ($_v3["task-count"] ?? null) : CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 54), "task-count", [], "array", false, false, true, 54));
                    // line 55
                    yield "      ";
                    $context["order_count"] = (($_v4 = CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 55)) && is_array($_v4) || $_v4 instanceof ArrayAccess && in_array($_v4::class, CoreExtension::ARRAY_LIKE_CLASSES, true) ? ($_v4["order-count"] ?? null) : CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 55), "order-count", [], "array", false, false, true, 55));
                    // line 56
                    yield "
      <li";
                    // line 57
                    yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "attributes", [], "any", false, false, true, 57), "addClass", ["nav-item"], "method", false, false, true, 57), "html", null, true);
                    yield ">

        ";
                    // line 59
                    if ((((($context["title"] ?? null) == "Messages") && CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 59)) && (CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 59), "toString", [], "method", false, false, true, 59) == "/my-messages"))) {
                        // line 60
                        yield "          <a href=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 60), "html", null, true);
                        yield "\" class=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, Twig\Extension\CoreExtension::join(($context["classes_link"] ?? null), " "));
                        yield "\">
            <i class=\"fa-solid fa-message\"></i>";
                        // line 61
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(($context["title"] ?? null));
                        yield "<span class=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(((Twig\Extension\CoreExtension::testEmpty(($context["patient_message_count"] ?? null))) ? ("") : ("message-count ")));
                        yield " patient-count\">";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["patient_message_count"] ?? null), "html", null, true);
                        yield "</span>
          </a>
        ";
                    } elseif (((                    // line 63
($context["title"] ?? null) == "Messages") && ( !CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 63) || (CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 63), "toString", [], "method", false, false, true, 63) == "")))) {
                        // line 64
                        yield "          <span class=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, Twig\Extension\CoreExtension::join(($context["classes_link"] ?? null), " "));
                        yield "\">
            <i class=\"fa-solid fa-message\"></i>";
                        // line 65
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(($context["title"] ?? null));
                        yield "<span class=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(((Twig\Extension\CoreExtension::testEmpty(($context["doctor_message_count"] ?? null))) ? ("") : ("message-count ")));
                        yield " doctor-count\">";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["doctor_message_count"] ?? null), "html", null, true);
                        yield "</span>
          </span>
        ";
                    } elseif ((((                    // line 67
($context["title"] ?? null) == "Patients") && CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 67)) && (CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 67), "toString", [], "method", false, false, true, 67) == ""))) {
                        // line 68
                        yield "          <span class=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, Twig\Extension\CoreExtension::join(($context["classes_link"] ?? null), " "));
                        yield "\">
           <i class=\"fa-solid fa-head-side-mask\"></i>";
                        // line 69
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(($context["title"] ?? null));
                        yield "<span class=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(((Twig\Extension\CoreExtension::testEmpty(($context["intake_count"] ?? null))) ? ("") : ("message-count ")));
                        yield " doctor-count\">";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["intake_count"] ?? null), "html", null, true);
                        yield "</span>
          </span>
         ";
                    } elseif ((((                    // line 71
($context["title"] ?? null) == "My Clinic Page") && CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 71)) && (CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 71), "toString", [], "method", false, false, true, 71) == ""))) {
                        // line 72
                        yield "          <span class=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, Twig\Extension\CoreExtension::join(($context["classes_link"] ?? null), " "));
                        yield "\">
            <i class=\"fa-solid fa-house-chimney-medical\"></i>";
                        // line 73
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(($context["title"] ?? null));
                        yield "<span class=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(((Twig\Extension\CoreExtension::testEmpty(($context["task_count"] ?? null))) ? ("") : ("message-count")));
                        yield " doctor-count\">";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["task_count"] ?? null), "html", null, true);
                        yield "</span><span class=\"";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(((Twig\Extension\CoreExtension::testEmpty(($context["order_count"] ?? null))) ? ("") : ("message-count")));
                        yield " doctor-count\">";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["order_count"] ?? null), "html", null, true);
                        yield "</span>
          </span>
        ";
                    } else {
                        // line 76
                        yield "          ";
                        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, $this->extensions['Drupal\Core\Template\TwigExtension']->getLink(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "title", [], "any", false, false, true, 76), CoreExtension::getAttribute($this->env, $this->source, $context["item"], "url", [], "any", false, false, true, 76), ["class" => ($context["classes_link"] ?? null)]), "html", null, true);
                        yield "
        ";
                    }
                    // line 78
                    yield "

        ";
                    // line 81
                    yield "        ";
                    if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 81)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                        // line 82
                        yield "         <ul>
              ";
                        // line 83
                        $context['_parent'] = $context;
                        $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, $context["item"], "below", [], "any", false, false, true, 83));
                        foreach ($context['_seq'] as $context["_key"] => $context["child_item"]) {
                            // line 84
                            yield "                ";
                            $context["child_title"] = Twig\Extension\CoreExtension::striptags(CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "title", [], "any", false, false, true, 84));
                            // line 85
                            yield "                ";
                            $context["child_url"] = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "url", [], "any", false, false, true, 85), "toString", [], "method", false, false, true, 85);
                            // line 86
                            yield "
                ";
                            // line 87
                            if (((($context["child_title"] ?? null) == "New Messages") && (($context["child_url"] ?? null) == "/new-messages"))) {
                                // line 88
                                yield "                  <li class=\"nav-item\">
                    <a href=\"";
                                // line 89
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "url", [], "any", false, false, true, 89), "html", null, true);
                                yield "\" class=\"nav-link\">
                      ";
                                // line 90
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "title", [], "any", false, false, true, 90), "html", null, true);
                                yield "<span class=\"";
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(((Twig\Extension\CoreExtension::testEmpty(($context["doctor_message_count"] ?? null))) ? ("") : ("message-count ")));
                                yield " doctor-count\">";
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["doctor_message_count"] ?? null), "html", null, true);
                                yield "</span>
                    </a>
                  </li>
                ";
                            } elseif (((                            // line 93
($context["child_title"] ?? null) == "Intake Forms") && (($context["child_url"] ?? null) == "/manage/intake"))) {
                                // line 94
                                yield "                <li class=\"nav-item\">
                    <a href=\"";
                                // line 95
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "url", [], "any", false, false, true, 95), "html", null, true);
                                yield "\" class=\"nav-link\">
                      ";
                                // line 96
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "title", [], "any", false, false, true, 96), "html", null, true);
                                yield "<span class=\"";
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(((Twig\Extension\CoreExtension::testEmpty(($context["intake_count"] ?? null))) ? ("") : ("message-count ")));
                                yield " doctor-count\">";
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["intake_count"] ?? null), "html", null, true);
                                yield "</span>
                    </a>
                  </li>
                ";
                            } elseif (((                            // line 99
($context["child_title"] ?? null) == "My Tasks") && (($context["child_url"] ?? null) == "/tasks"))) {
                                // line 100
                                yield "                  <li class=\"nav-item\">
                    <a href=\"";
                                // line 101
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "url", [], "any", false, false, true, 101), "html", null, true);
                                yield "\" class=\"nav-link\">
                      ";
                                // line 102
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "title", [], "any", false, false, true, 102), "html", null, true);
                                yield "<span class=\"";
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(((Twig\Extension\CoreExtension::testEmpty(($context["task_count"] ?? null))) ? ("") : ("message-count ")));
                                yield " doctor-count\">";
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["task_count"] ?? null), "html", null, true);
                                yield "</span>
                    </a>
                  </li>
                ";
                            } elseif (((                            // line 105
($context["child_title"] ?? null) == "My Orders") && (($context["child_url"] ?? null) == "/clinican/orders"))) {
                                // line 106
                                yield "                  <li class=\"nav-item\">
                    <a href=\"";
                                // line 107
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "url", [], "any", false, false, true, 107), "html", null, true);
                                yield "\" class=\"nav-link\">
                      ";
                                // line 108
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "title", [], "any", false, false, true, 108), "html", null, true);
                                yield "<span class=\"";
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->renderVar(((Twig\Extension\CoreExtension::testEmpty(($context["order_count"] ?? null))) ? ("") : ("message-count ")));
                                yield " doctor-count\">";
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["order_count"] ?? null), "html", null, true);
                                yield "</span>
                    </a>
                  </li>
                ";
                            } else {
                                // line 112
                                yield "                  <li class=\"nav-item\">
                    <a href=\"";
                                // line 113
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "url", [], "any", false, false, true, 113), "html", null, true);
                                yield "\" class=\"nav-link\">";
                                yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, CoreExtension::getAttribute($this->env, $this->source, $context["child_item"], "title", [], "any", false, false, true, 113), "html", null, true);
                                yield "</a>
                  </li>
                ";
                            }
                            // line 116
                            yield "              ";
                        }
                        $_parent = $context['_parent'];
                        unset($context['_seq'], $context['_key'], $context['child_item'], $context['_parent']);
                        $context = array_intersect_key($context, $_parent) + $_parent;
                        // line 117
                        yield "            </ul>

        ";
                    }
                    // line 120
                    yield "
      </li>
    ";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['item'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 123
                yield "    </ul>
  ";
            }
            yield from [];
        })())) ? '' : new Markup($tmp, $this->env->getCharset());
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "themes/custom/chirothintracker_subtheme/templates/navigation/menu--menu-header-right.html.twig";
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
        return array (  347 => 123,  339 => 120,  334 => 117,  328 => 116,  320 => 113,  317 => 112,  306 => 108,  302 => 107,  299 => 106,  297 => 105,  287 => 102,  283 => 101,  280 => 100,  278 => 99,  268 => 96,  264 => 95,  261 => 94,  259 => 93,  249 => 90,  245 => 89,  242 => 88,  240 => 87,  237 => 86,  234 => 85,  231 => 84,  227 => 83,  224 => 82,  221 => 81,  217 => 78,  211 => 76,  197 => 73,  192 => 72,  190 => 71,  181 => 69,  176 => 68,  174 => 67,  165 => 65,  160 => 64,  158 => 63,  149 => 61,  142 => 60,  140 => 59,  135 => 57,  132 => 56,  129 => 55,  126 => 54,  123 => 53,  120 => 52,  117 => 51,  114 => 49,  111 => 48,  109 => 47,  106 => 46,  104 => 43,  103 => 42,  102 => 41,  101 => 39,  99 => 38,  94 => 37,  90 => 35,  84 => 33,  81 => 32,  78 => 31,  75 => 30,  61 => 29,  53 => 126,  48 => 27,  45 => 22,  43 => 21,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "themes/custom/chirothintracker_subtheme/templates/navigation/menu--menu-header-right.html.twig", "/app/web/themes/custom/chirothintracker_subtheme/templates/navigation/menu--menu-header-right.html.twig");
    }

    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }

    public function checkSecurity()
    {
        static $tags = ["import" => 21, "macro" => 29, "if" => 31, "for" => 37, "set" => 39];
        static $filters = ["escape" => 33, "striptags" => 47, "trim" => 48, "join" => 60, "raw" => 61];
        static $functions = ["link" => 76];

        try {
            $this->sandbox->checkSecurity(
                [0 => "import", 1 => "macro", 2 => "if", 3 => "for", 4 => "set"],
                [0 => "escape", 1 => "striptags", 2 => "trim", 3 => "join", 4 => "raw"],
                [0 => "link"],
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
