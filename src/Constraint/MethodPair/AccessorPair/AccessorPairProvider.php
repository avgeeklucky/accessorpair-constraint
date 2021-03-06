<?php
declare(strict_types=1);

namespace DigitalRevolution\AccessorPairConstraint\Constraint\MethodPair\AccessorPair;

use DigitalRevolution\AccessorPairConstraint\Constraint\Typehint\TypehintResolver;
use LogicException;
use phpDocumentor\Reflection\Types\Array_;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

class AccessorPairProvider
{
    private const GET_PREFIXES = ['get', 'is', 'has'];
    private const SET_PREFIXES = ['set', 'add'];

    /**
     * Inspect the given class, using reflection, and pair all get/set methods together
     * Loops over the public methods, and for each "getter" it tries to find the corresponding "set" and/or "add" method
     *
     * @return AccessorPair[]
     * @throws ReflectionException
     * @throws LogicException
     */
    public function getAccessorPairs(ReflectionClass $class): array
    {
        $pairs = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Check multiple "getter" prefixes, add each getter method with corresponding setter to the inspectionMethod list
            $methodName = $method->getName();
            foreach (static::GET_PREFIXES as $getterPrefix) {
                if (strpos($methodName, $getterPrefix) !== 0) {
                    continue;
                }

                // Try and find the corresponding set/add method
                $baseMethodName = substr($methodName, strlen($getterPrefix));
                foreach (static::SET_PREFIXES as $setterPrefix) {
                    $setterName = $setterPrefix . $baseMethodName;
                    if ($class->hasMethod($setterName) === false) {
                        continue;
                    }

                    $setterMethod = $class->getMethod($setterName);
                    if ($setterMethod->isPublic() === false) {
                        continue;
                    }

                    $accessorPair = new AccessorPair($class, $method, $setterMethod);
                    if ($this->validateAccessorPair($accessorPair)) {
                        $pairs[] = $accessorPair;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * @throws LogicException
     */
    protected function validateAccessorPair(AccessorPair $accessorPair): bool
    {
        $getterMethod = $accessorPair->getGetMethod();
        $setterMethod = $accessorPair->getSetMethod();

        // We can only test accessorPairs where the getter has no parameter, and the setter has one parameter
        if ($getterMethod->getNumberOfParameters() !== 0) {
            return false;
        }
        if ($setterMethod->getNumberOfParameters() !== 1) {
            return false;
        }

        // Check if the getter's return typehint matches the setter's parameter typehint
        $parameter = $setterMethod->getParameters()[0];
        if ($accessorPair->hasMultiGetter() || $parameter->isVariadic()) {
            $paramType  = (string)(new TypehintResolver($setterMethod))->getParamTypehint($parameter);
            $returnType = (new TypehintResolver($getterMethod))->getReturnTypehint();

            // The getter should return an array containing the setter's input values
            if ($returnType instanceof Array_ && (string)$returnType->getValueType() === $paramType) {
                return true;
            }

            // Allow getter to return typed array or null
            return (string)$returnType === $paramType . '[]|null';
        }

        $paramType  = (string)(new TypehintResolver($setterMethod))->getParamTypehint($parameter);
        $returnType = (string)(new TypehintResolver($getterMethod))->getReturnTypehint();

        // Getter should return the same value, or nullable value
        return $paramType === $returnType || $paramType . '|null' === $returnType;
    }
}
