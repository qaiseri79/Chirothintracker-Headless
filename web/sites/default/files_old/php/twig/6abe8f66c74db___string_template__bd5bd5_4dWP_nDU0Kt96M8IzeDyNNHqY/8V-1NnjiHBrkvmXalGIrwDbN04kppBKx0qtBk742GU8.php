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

/* __string_template__bd5bd5b4e58e281abddd998a2736fabc */
class __TwigTemplate_f321ca8a827b560f076ef03809b46126 extends Template
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
        // line 1
        yield "<div class=\"patient-info-on-details\">
    <div class=\"patient-personal-info\">
        <h2>";
        // line 3
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["field_full_name"] ?? null), "html", null, true);
        yield "</h2>
        <p><i class=\"fa-solid fa-envelope\"></i>&nbsp;";
        // line 4
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["mail"] ?? null), "html", null, true);
        yield "</p>
    </div>
    <div class=\"patient-weight-info\">
        <ul>
            <li><b>Start Date:</b>&nbsp;";
        // line 8
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["field_program_start_date"] ?? null), "html", null, true);
        yield "</li>
            <li><b>Current&nbsp;Program&nbsp;Day:</b>&nbsp;";
        // line 9
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["field_user_current_program_day_c"] ?? null), "html", null, true);
        yield "</li>
            <li><b>Start&nbsp;Weight:</b>&nbsp;";
        // line 10
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["field_program_start_weight"] ?? null), "html", null, true);
        yield "</li>
            <li><b>Goal&nbsp;Weight:</b>&nbsp;";
        // line 11
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["field_goal_weight"] ?? null), "html", null, true);
        yield "</li>
            <li><b>Net&nbsp;Loss:</b>&nbsp;";
        // line 12
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["field_net_weight_loss"] ?? null), "html", null, true);
        yield "</li>
            <li><b>%&nbsp;of&nbsp;Goal:</b>&nbsp;";
        // line 13
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["field_goal_achieved"] ?? null), "html", null, true);
        yield "</li>
            <li><b>Overall&nbsp;Loss:</b>&nbsp;";
        // line 14
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["field_gross_weight_loss"] ?? null), "html", null, true);
        yield "</li>
            <li><b>Inches:</b>&nbsp;";
        // line 15
        yield $this->extensions['Drupal\Core\Template\TwigExtension']->escapeFilter($this->env, ($context["field_net_inches_lost"] ?? null), "html", null, true);
        yield "</li>
        </ul>
    </div>
</div>";
        $this->env->getExtension('\Drupal\Core\Template\TwigExtension')
            ->checkDeprecations($context, ["field_full_name", "mail", "field_program_start_date", "field_user_current_program_day_c", "field_program_start_weight", "field_goal_weight", "field_net_weight_loss", "field_goal_achieved", "field_gross_weight_loss", "field_net_inches_lost"]);        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "__string_template__bd5bd5b4e58e281abddd998a2736fabc";
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
        return array (  86 => 15,  82 => 14,  78 => 13,  74 => 12,  70 => 11,  66 => 10,  62 => 9,  58 => 8,  51 => 4,  47 => 3,  43 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "__string_template__bd5bd5b4e58e281abddd998a2736fabc", "");
    }
    
    public function ensureSecurityChecked(): void
    {
        if ($this->sandbox->isSandboxed($this->source)) {
            $this->checkSecurity();
        }
    }
    
    public function checkSecurity()
    {
        static $tags = [];
        static $filters = ["escape" => 3];
        static $functions = [];

        try {
            $this->sandbox->checkSecurity(
                [],
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
