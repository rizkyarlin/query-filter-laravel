<?php

namespace LIQRGV\QueryFilter;

use HaydenPierce\ClassFinder\ClassFinder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Laravel\Lumen\Routing\Controller;
use LIQRGV\QueryFilter\Exception\ModelNotFoundException;
use LIQRGV\QueryFilter\Exception\NotModelException;
use LIQRGV\QueryFilter\Struct\FilterStruct;
use LIQRGV\QueryFilter\Struct\ModelBuilderStruct;

class RequestParser
{

    private static $ALLOWED_OPERATOR = [
        "=",
        "!=",
        ">",
        "<",
        "is",
        "!is",
        "in",
        "!in",
        "between",
    ];
    /**
     * @var array
     */
    private $modelNamespaces;
    /**
     * @var Request
     */
    private $request;

    public function __construct(Request $request)
    {
        $requestParserConfig = Config::get('request_parser');
        if(is_null($requestParserConfig) || empty($requestParserConfig)) {
            $this->modelNamespaces = ["App\\Models"];
        } else {
            $this->modelNamespaces = $requestParserConfig['model_namespaces'];
        }

        $this->request = $request;
    }

    public function getBuilder()
    {
        $modelBuilderStruct = $this->createModelBuilderStruct($this->request);
        $model = $this->createModel($modelBuilderStruct->baseModelName);

        return $this->applyFilter($model::query(), $modelBuilderStruct->filters);
    }

    private function createModelBuilderStruct(Request $request): ModelBuilderStruct
    {
        $filterQuery = $request->filter ?: [];

        $baseModelName = $this->getBaseModelName($request);
        $filters = $this->parseFilter($filterQuery);

        return new ModelBuilderStruct($baseModelName, $filters);
    }

    private function getBaseModelName(Request $request): string
    {
        $modelCandidates = [];
        $route = $request->route();
        $controller = $this->getControllerFromRoute($route);
        if ($controller) {
            $stringToRemove = "controller";
            $className = class_basename($controller);
            $maybeBaseModel = substr_replace($className, '', strrpos(strtolower($className), $stringToRemove), strlen($stringToRemove));
            $modelCandidates[] = $maybeBaseModel;

            $modelName = $this->getModelFromNamespaces($maybeBaseModel, $this->modelNamespaces);
            if ($modelName) {
                return $modelName;
            }
        }

        $exploded = explode("/", $request->getRequesturi());
        $lastURISegment = strtolower(end($exploded));
        $camelizeURI = str_replace('_', '', ucwords($lastURISegment, '_'));
        $modelCandidates[] = $camelizeURI;

        $modelName = $this->getModelFromNamespaces($camelizeURI, $this->modelNamespaces);
        if ($modelName) {
            return $modelName;
        }

        $errorMessage = "Model not found after looking on ";
        $searchPath = [];
        foreach ($modelCandidates as $candidate) {
            foreach ($this->modelNamespaces as $modelNamespace) {
                $searchPath[] = $modelNamespace . '\\' . $candidate;
            }
        }

        $errorMessage .= join(', ', $searchPath);

        throw new ModelNotFoundException($errorMessage);
    }

    private function parseFilter(array $filterQuery = []): array
    {
        $filters = [];

        if (is_array($filterQuery)) {
            foreach ($filterQuery as $key => $operatorValuePairs) {
                if (is_array($operatorValuePairs)) {
                    foreach ($operatorValuePairs as $operator => $value) {
                        if (in_array($operator, static::$ALLOWED_OPERATOR)) {
                            $filters[] = new FilterStruct($key, $operator, $value);
                        }
                    }
                }
            }
        }

        return $filters;
    }

    private function getModelFromNamespaces(string $modelName, array $modelNamespaces)
    {
        foreach ($modelNamespaces as $modelNamespace) {
            $classes = ClassFinder::getClassesInNamespace($modelNamespace);

            foreach ($classes as $class) {
                if ($class == $modelNamespace . '\\' . $modelName) {
                    return $class;
                }
            }
        }

        return null;
    }

    private function applyFilter(Builder $builder, array $filters): Builder
    {
        foreach ($filters as $filterStruct) {
            $builder = $filterStruct->apply($builder);
        }

        return $builder;
    }

    private function createModel(string $baseModelName)
    {
        $model = new $baseModelName;
        if (!($model instanceof Model)) {
            throw new NotModelException($baseModelName);
        }

        return $model;
    }

    private function getControllerFromRoute($route)
    {
        if (is_array($route)) {
            $controllerWithMethod = current($route[1]);
            $splitedControllerMethod = explode('@', $controllerWithMethod);
            $routingHandler = current($splitedControllerMethod);
            $maybeController = new $routingHandler;

            return new $maybeController instanceof Controller ? $maybeController : null;
        }

        return $route->controller;
    }
}
