<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Form;

use Symfony\Component\Form\Exception\FormException;
use Symfony\Component\Form\Exception\AlreadyBoundException;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Util\FormUtil;
use Symfony\Component\Form\Util\PropertyPath;
use Symfony\Component\HttpFoundation\Request;

/**
 * Form represents a form.
 *
 * To implement your own form fields, you need to have a thorough understanding
 * of the data flow within a form. A form stores its data in three different
 * representations:
 *
 *   (1) the "model" format required by the form's object
 *   (2) the "normalized" format for internal processing
 *   (3) the "view" format used for display
 *
 * A date field, for example, may store a date as "Y-m-d" string (1) in the
 * object. To facilitate processing in the field, this value is normalized
 * to a DateTime object (2). In the HTML representation of your form, a
 * localized string (3) is presented to and modified by the user.
 *
 * In most cases, format (1) and format (2) will be the same. For example,
 * a checkbox field uses a Boolean value for both internal processing and
 * storage in the object. In these cases you simply need to set a value
 * transformer to convert between formats (2) and (3). You can do this by
 * calling addViewTransformer().
 *
 * In some cases though it makes sense to make format (1) configurable. To
 * demonstrate this, let's extend our above date field to store the value
 * either as "Y-m-d" string or as timestamp. Internally we still want to
 * use a DateTime object for processing. To convert the data from string/integer
 * to DateTime you can set a normalization transformer by calling
 * addNormTransformer(). The normalized data is then converted to the displayed
 * data as described before.
 *
 * The conversions (1) -> (2) -> (3) use the transform methods of the transformers.
 * The conversions (3) -> (2) -> (1) use the reverseTransform methods of the transformers.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class Form implements \IteratorAggregate, FormInterface
{
    /**
     * The form's configuration
     * @var FormConfigInterface
     */
    private $config;

    /**
     * The parent of this form
     * @var FormInterface
     */
    private $parent;

    /**
     * The children of this form
     * @var array An array of FormInterface instances
     */
    private $children = array();

    /**
     * The errors of this form
     * @var array An array of FormError instances
     */
    private $errors = array();

    /**
     * Whether this form is bound
     * @var Boolean
     */
    private $bound = false;

    /**
     * The form data in model format
     * @var mixed
     */
    private $modelData;

    /**
     * The form data in normalized format
     * @var mixed
     */
    private $normData;

    /**
     * The form data in view format
     * @var mixed
     */
    private $viewData;

    /**
     * The bound values that don't belong to any children
     * @var array
     */
    private $extraData = array();

    /**
     * Whether the data in model, normalized and view format is
     * synchronized. Data may not be synchronized if transformation errors
     * occur.
     * @var Boolean
     */
    private $synchronized = true;

    /**
     * Whether the form's data has been initialized.
     *
     * When the data is initialized with its default value, that default value
     * is passed through the transformer chain in order to synchronize the
     * model, normalized and view format for the first time. This is done
     * lazily in order to save performance when {@link setData()} is called
     * manually, making the initialization with the configured default value
     * superfluous.
     *
     * @var Boolean
     */
    private $initialized = false;

    /**
     * Whether setData() is currently being called.
     * @var Boolean
     */
    private $lockSetData = false;

    /**
     * Creates a new form based on the given configuration.
     *
     * @param FormConfigInterface $config The form configuration.
     */
    public function __construct(FormConfigInterface $config)
    {
        if (!$config instanceof ImmutableFormConfig) {
            $config = new ImmutableFormConfig($config);
        }

        // Compound forms always need a data mapper, otherwise calls to
        // `setData` and `add` will not lead to the correct population of
        // the child forms.
        if ($config->getCompound() && !$config->getDataMapper()) {
            throw new FormException('Compound forms need a data mapper');
        }

        $this->config = $config;
    }

    public function __clone()
    {
        foreach ($this->children as $key => $child) {
            $this->children[$key] = clone $child;
        }
    }

    /**
     * Returns the configuration of the form.
     *
     * @return ImmutableFormConfig The form's immutable configuration.
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Returns the name by which the form is identified in forms.
     *
     * @return string The name of the form.
     */
    public function getName()
    {
        return $this->config->getName();
    }

    /**
     * {@inheritdoc}
     */
    public function getPropertyPath()
    {
        if (null !== $this->config->getPropertyPath()) {
            return $this->config->getPropertyPath();
        }

        if (null === $this->getName() || '' === $this->getName()) {
            return null;
        }

        if ($this->hasParent() && null === $this->getParent()->getConfig()->getDataClass()) {
            return new PropertyPath('[' . $this->getName() . ']');
        }

        return new PropertyPath($this->getName());
    }

    /**
     * Returns the types used by this form.
     *
     * @return array An array of FormTypeInterface
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link getConfig()} and {@link FormConfigInterface::getType()} instead.
     */
    public function getTypes()
    {
        $types = array();

        for ($type = $this->config->getType(); null !== $type; $type = $type->getParent()) {
            array_unshift($types, $type->getInnerType());
        }

        return $types;
    }

    /**
     * {@inheritdoc}
     */
    public function isRequired()
    {
        if (null === $this->parent || $this->parent->isRequired()) {
            return $this->config->getRequired();
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function isDisabled()
    {
        if (null === $this->parent || !$this->parent->isDisabled()) {
            return $this->config->getDisabled();
        }

        return true;
    }

    /**
     * Sets the parent form.
     *
     * @param FormInterface $parent The parent form
     *
     * @return Form The current form
     */
    public function setParent(FormInterface $parent = null)
    {
        if ($this->bound) {
            throw new AlreadyBoundException('You cannot set the parent of a bound form');
        }

        if ('' === $this->config->getName()) {
            throw new FormException('A form with an empty name cannot have a parent form.');
        }

        $this->parent = $parent;

        return $this;
    }

    /**
     * Returns the parent form.
     *
     * @return FormInterface The parent form
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Returns whether the form has a parent.
     *
     * @return Boolean
     */
    public function hasParent()
    {
        return null !== $this->parent;
    }

    /**
     * Returns the root of the form tree.
     *
     * @return FormInterface The root of the tree
     */
    public function getRoot()
    {
        return $this->parent ? $this->parent->getRoot() : $this;
    }

    /**
     * Returns whether the form is the root of the form tree.
     *
     * @return Boolean
     */
    public function isRoot()
    {
        return !$this->hasParent();
    }

    /**
     * Returns whether the form has an attribute with the given name.
     *
     * @param  string $name The name of the attribute.
     *
     * @return Boolean Whether the attribute exists.
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link getConfig()} and {@link FormConfigInterface::hasAttribute()} instead.
     */
    public function hasAttribute($name)
    {
        return $this->config->hasAttribute($name);
    }

    /**
     * Returns the value of the attributes with the given name.
     *
     * @param  string $name The name of the attribute
     *
     * @return mixed The attribute value.
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link getConfig()} and {@link FormConfigInterface::getAttribute()} instead.
     */
    public function getAttribute($name)
    {
        return $this->config->getAttribute($name);
    }

    /**
     * Updates the form with default data.
     *
     * @param mixed $modelData The data formatted as expected for the underlying object
     *
     * @return Form The current form
     */
    public function setData($modelData)
    {
        // If the form is bound while disabled, it is set to bound, but the data is not
        // changed. In such cases (i.e. when the form is not initialized yet) don't
        // abort this method.
        if ($this->bound && $this->initialized) {
            throw new AlreadyBoundException('You cannot change the data of a bound form');
        }

        // Don't allow modifications of the configured data if the data is locked
        if ($this->config->getDataLocked() && $modelData !== $this->config->getData()) {
            return $this;
        }

        if (is_object($modelData) && !$this->config->getByReference()) {
            $modelData = clone $modelData;
        }

        if ($this->lockSetData) {
            throw new FormException('A cycle was detected. Listeners to the PRE_SET_DATA event must not call setData(). You should call setData() on the FormEvent object instead.');
        }

        $this->lockSetData = true;

        // Hook to change content of the data
        $event = new FormEvent($this, $modelData);
        $this->config->getEventDispatcher()->dispatch(FormEvents::PRE_SET_DATA, $event);
        // BC until 2.3
        $this->config->getEventDispatcher()->dispatch(FormEvents::SET_DATA, $event);
        $modelData = $event->getData();

        // Treat data as strings unless a value transformer exists
        if (!$this->config->getViewTransformers() && !$this->config->getModelTransformers() && is_scalar($modelData)) {
            $modelData = (string) $modelData;
        }

        // Synchronize representations - must not change the content!
        $normData = $this->modelToNorm($modelData);
        $viewData = $this->normToView($normData);

        // Validate if view data matches data class (unless empty)
        if (!FormUtil::isEmpty($viewData)) {
            $dataClass = $this->config->getDataClass();

            $actualType = is_object($viewData) ? 'an instance of class ' . get_class($viewData) : ' a(n) ' . gettype($viewData);

            if (null === $dataClass && is_object($viewData) && !$viewData instanceof \ArrayAccess) {
                $expectedType = 'scalar, array or an instance of \ArrayAccess';

                throw new FormException(
                    'The form\'s view data is expected to be of type ' . $expectedType . ', ' .
                    'but is ' . $actualType . '. You ' .
                    'can avoid this error by setting the "data_class" option to ' .
                    '"' . get_class($viewData) . '" or by adding a view transformer ' .
                    'that transforms ' . $actualType . ' to ' . $expectedType . '.'
                );
            }

            if (null !== $dataClass && !$viewData instanceof $dataClass) {
                throw new FormException(
                    'The form\'s view data is expected to be an instance of class ' .
                    $dataClass . ', but is '. $actualType . '. You can avoid this error ' .
                    'by setting the "data_class" option to null or by adding a view ' .
                    'transformer that transforms ' . $actualType . ' to an instance of ' .
                    $dataClass . '.'
                );
            }
        }

        $this->modelData = $modelData;
        $this->normData = $normData;
        $this->viewData = $viewData;
        $this->synchronized = true;
        $this->initialized = true;
        $this->lockSetData = false;

        // It is not necessary to invoke this method if the form doesn't have children,
        // even if the form is compound.
        if (count($this->children) > 0) {
            // Update child forms from the data
            $this->config->getDataMapper()->mapDataToForms($viewData, $this->children);
        }

        $event = new FormEvent($this, $modelData);
        $this->config->getEventDispatcher()->dispatch(FormEvents::POST_SET_DATA, $event);

        return $this;
    }

    /**
     * Returns the data in the format needed for the underlying object.
     *
     * @return mixed
     */
    public function getData()
    {
        if (!$this->initialized) {
            $this->setData($this->config->getData());
        }

        return $this->modelData;
    }

    /**
     * Returns the normalized data of the form.
     *
     * @return mixed When the form is not bound, the default data is returned.
     *                When the form is bound, the normalized bound data is
     *                returned if the form is valid, null otherwise.
     */
    public function getNormData()
    {
        if (!$this->initialized) {
            $this->setData($this->config->getData());
        }

        return $this->normData;
    }

    /**
     * Returns the data transformed by the value transformer.
     *
     * @return string
     */
    public function getViewData()
    {
        if (!$this->initialized) {
            $this->setData($this->config->getData());
        }

        return $this->viewData;
    }

    /**
     * Alias of {@link getViewData()}.
     *
     * @return string
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link getViewData()} instead.
     */
    public function getClientData()
    {
        return $this->getViewData();
    }

    /**
     * Returns the extra data.
     *
     * @return array The bound data which do not belong to a child
     */
    public function getExtraData()
    {
        return $this->extraData;
    }

    /**
     * Binds data to the form, transforms and validates it.
     *
     * @param string|array $submittedData The data
     *
     * @return Form The current form
     *
     * @throws UnexpectedTypeException
     */
    public function bind($submittedData)
    {
        if ($this->bound) {
            throw new AlreadyBoundException('A form can only be bound once');
        }

        if ($this->isDisabled()) {
            $this->bound = true;

            return $this;
        }

        // The data must be initialized if it was not initialized yet.
        // This is necessary to guarantee that the *_SET_DATA listeners
        // are always invoked before bind() takes place.
        if (!$this->initialized) {
            $this->setData($this->config->getData());
        }

        // Don't convert NULL to a string here in order to determine later
        // whether an empty value has been submitted or whether no value has
        // been submitted at all. This is important for processing checkboxes
        // and radio buttons with empty values.
        if (is_scalar($submittedData)) {
            $submittedData = (string) $submittedData;
        }

        // Initialize errors in the very beginning so that we don't lose any
        // errors added during listeners
        $this->errors = array();

        $modelData = null;
        $normData = null;
        $extraData = array();
        $synchronized = false;

        // Hook to change content of the data bound by the browser
        $event = new FormEvent($this, $submittedData);
        $this->config->getEventDispatcher()->dispatch(FormEvents::PRE_BIND, $event);
        // BC until 2.3
        $this->config->getEventDispatcher()->dispatch(FormEvents::BIND_CLIENT_DATA, $event);
        $submittedData = $event->getData();

        // By default, the submitted data is also the data in view format
        $viewData = $submittedData;

        // Check whether the form is compound.
        // This check is preferrable over checking the number of children,
        // since forms without children may also be compound.
        // (think of empty collection forms)
        if ($this->config->getCompound()) {
            if (!is_array($submittedData)) {
                if (!FormUtil::isEmpty($submittedData)) {
                    throw new UnexpectedTypeException($submittedData, 'array');
                }

                $submittedData = array();
            }

            foreach ($this->children as $name => $child) {
                if (!isset($submittedData[$name])) {
                    $submittedData[$name] = null;
                }
            }

            foreach ($submittedData as $name => $value) {
                if ($this->has($name)) {
                    $this->children[$name]->bind($value);
                } else {
                    $extraData[$name] = $value;
                }
            }

            // If the form is compound, the default data in view format
            // is reused. The data of the children is merged into this
            // default data using the data mapper.
            $viewData = $this->getViewData();
        }

        if (FormUtil::isEmpty($viewData)) {
            $emptyData = $this->config->getEmptyData();

            if ($emptyData instanceof \Closure) {
                /* @var \Closure $emptyData */
                $emptyData = $emptyData($this, $viewData);
            }

            $viewData = $emptyData;
        }

        // Merge form data from children into existing view data
        // It is not necessary to invoke this method if the form has no children,
        // even if it is compound.
        if (count($this->children) > 0) {
            $this->config->getDataMapper()->mapFormsToData($this->children, $viewData);
        }

        try {
            // Normalize data to unified representation
            $normData = $this->viewToNorm($viewData);

            // Hook to change content of the data into the normalized
            // representation
            $event = new FormEvent($this, $normData);
            $this->config->getEventDispatcher()->dispatch(FormEvents::BIND, $event);
            // BC until 2.3
            $this->config->getEventDispatcher()->dispatch(FormEvents::BIND_NORM_DATA, $event);
            $normData = $event->getData();

            // Synchronize representations - must not change the content!
            $modelData = $this->normToModel($normData);
            $viewData = $this->normToView($normData);

            $synchronized = true;
        } catch (TransformationFailedException $e) {
        }

        $this->bound = true;
        $this->modelData = $modelData;
        $this->normData = $normData;
        $this->viewData = $viewData;
        $this->extraData = $extraData;
        $this->synchronized = $synchronized;

        $event = new FormEvent($this, $viewData);
        $this->config->getEventDispatcher()->dispatch(FormEvents::POST_BIND, $event);

        foreach ($this->config->getValidators() as $validator) {
            $validator->validate($this);
        }

        return $this;
    }

    /**
     * Binds a request to the form.
     *
     * If the request method is POST, PUT or GET, the data is bound to the form,
     * transformed and written into the form data (an object or an array).
     *
     * @param Request $request The request to bind to the form
     *
     * @return Form This form
     *
     * @throws FormException if the method of the request is not one of GET, POST or PUT
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link FormConfigInterface::bind()} instead.
     */
    public function bindRequest(Request $request)
    {
        return $this->bind($request);
    }

    /**
     * Adds an error to this form.
     *
     * @param FormError $error
     *
     * @return Form The current form
     */
    public function addError(FormError $error)
    {
        if ($this->parent && $this->getErrorBubbling()) {
            $this->parent->addError($error);
        } else {
            $this->errors[] = $error;
        }

        return $this;
    }

    /**
     * Returns whether errors bubble up to the parent.
     *
     * @return Boolean
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link getConfig()} and {@link FormConfigInterface::getErrorBubbling()} instead.
     */
    public function getErrorBubbling()
    {
        return $this->config->getErrorBubbling();
    }

    /**
     * Returns whether the form is bound.
     *
     * @return Boolean true if the form is bound to input values, false otherwise
     */
    public function isBound()
    {
        return $this->bound;
    }

    /**
     * Returns whether the data in the different formats is synchronized.
     *
     * @return Boolean
     */
    public function isSynchronized()
    {
        return $this->synchronized;
    }

    /**
     * Returns whether the form is empty.
     *
     * @return Boolean
     */
    public function isEmpty()
    {
        foreach ($this->children as $child) {
            if (!$child->isEmpty()) {
                return false;
            }
        }

        return FormUtil::isEmpty($this->modelData) || array() === $this->modelData;
    }

    /**
     * Returns whether the form is valid.
     *
     * @return Boolean
     */
    public function isValid()
    {
        if (!$this->bound) {
            throw new \LogicException('You cannot call isValid() on a form that is not bound.');
        }

        if ($this->hasErrors()) {
            return false;
        }

        if (!$this->isDisabled()) {
            foreach ($this->children as $child) {
                if (!$child->isValid()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Returns whether or not there are errors.
     *
     * @return Boolean true if form is bound and not valid
     */
    public function hasErrors()
    {
        // Don't call isValid() here, as its semantics are slightly different
        // Forms are not valid if their children are invalid, but
        // hasErrors() returns only true if a form itself has errors
        return count($this->errors) > 0;
    }

    /**
     * Returns all errors.
     *
     * @return array An array of FormError instances that occurred during binding
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Returns a string representation of all form errors (including children errors).
     *
     * This method should only be used to help debug a form.
     *
     * @param integer $level The indentation level (used internally)
     *
     * @return string A string representation of all errors
     */
    public function getErrorsAsString($level = 0)
    {
        $errors = '';
        foreach ($this->errors as $error) {
            $errors .= str_repeat(' ', $level).'ERROR: '.$error->getMessage()."\n";
        }

        foreach ($this->children as $key => $child) {
            $errors .= str_repeat(' ', $level).$key.":\n";
            if ($err = $child->getErrorsAsString($level + 4)) {
                $errors .= $err;
            } else {
                $errors .= str_repeat(' ', $level + 4)."No errors\n";
            }
        }

        return $errors;
    }

    /**
     * Returns the DataTransformers.
     *
     * @return array An array of DataTransformerInterface
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link getConfig()} and {@link FormConfigInterface::getModelTransformers()} instead.
     */
    public function getNormTransformers()
    {
        return $this->config->getModelTransformers();
    }

    /**
     * Returns the DataTransformers.
     *
     * @return array An array of DataTransformerInterface
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link getConfig()} and {@link FormConfigInterface::getViewTransformers()} instead.
     */
    public function getClientTransformers()
    {
        return $this->config->getViewTransformers();
    }

    /**
     * {@inheritdoc}
     */
    public function all()
    {
        return $this->children;
    }

    /**
     * Returns all children in this group.
     *
     * @return array
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link all()} instead.
     */
    public function getChildren()
    {
        return $this->all();
    }

    /**
     * Returns whether the form has children.
     *
     * @return Boolean
     *
     * @deprecated Deprecated since version 2.1, to be removed in 2.3. Use
     *             {@link count()} instead.
     */
    public function hasChildren()
    {
        return count($this->children) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function add(FormInterface $child)
    {
        if ($this->bound) {
            throw new AlreadyBoundException('You cannot add children to a bound form');
        }

        if (!$this->config->getCompound()) {
            throw new FormException('You cannot add children to a simple form. Maybe you should set the option "compound" to true?');
        }

        // Obtain the view data
        $viewData = null;

        // If setData() is currently being called, there is no need to call
        // mapDataToForms() here, as mapDataToForms() is called at the end
        // of setData() anyway. Not doing this check leads to an endless
        // recursion when initializing the form lazily and an event listener
        // (such as ResizeFormListener) adds fields depending on the data:
        //
        //  * setData() is called, the form is not initialized yet
        //  * add() is called by the listener (setData() is not complete, so
        //    the form is still not initialized)
        //  * getViewData() is called
        //  * setData() is called since the form is not initialized yet
        //  * ... endless recursion ...
        if (!$this->lockSetData) {
            $viewData = $this->getViewData();
        }

        $this->children[$child->getName()] = $child;

        $child->setParent($this);

        if (!$this->lockSetData) {
            $this->config->getDataMapper()->mapDataToForms($viewData, array($child));
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function remove($name)
    {
        if ($this->bound) {
            throw new AlreadyBoundException('You cannot remove children from a bound form');
        }

        if (isset($this->children[$name])) {
            $this->children[$name]->setParent(null);

            unset($this->children[$name]);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function has($name)
    {
        return isset($this->children[$name]);
    }

    /**
     * {@inheritdoc}
     */
    public function get($name)
    {
        if (isset($this->children[$name])) {
            return $this->children[$name];
        }

        throw new \InvalidArgumentException(sprintf('Child "%s" does not exist.', $name));
    }

    /**
     * Returns true if the child exists (implements the \ArrayAccess interface).
     *
     * @param string $name The name of the child
     *
     * @return Boolean true if the widget exists, false otherwise
     */
    public function offsetExists($name)
    {
        return $this->has($name);
    }

    /**
     * Returns the form child associated with the name (implements the \ArrayAccess interface).
     *
     * @param string $name The offset of the value to get
     *
     * @return FormInterface A form instance
     */
    public function offsetGet($name)
    {
        return $this->get($name);
    }

    /**
     * Adds a child to the form (implements the \ArrayAccess interface).
     *
     * @param string        $name  Ignored. The name of the child is used.
     * @param FormInterface $child The child to be added
     */
    public function offsetSet($name, $child)
    {
        $this->add($child);
    }

    /**
     * Removes the child with the given name from the form (implements the \ArrayAccess interface).
     *
     * @param string $name The name of the child to be removed
     */
    public function offsetUnset($name)
    {
        $this->remove($name);
    }

    /**
     * Returns the iterator for this group.
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->children);
    }

    /**
     * Returns the number of form children (implements the \Countable interface).
     *
     * @return integer The number of embedded form children
     */
    public function count()
    {
        return count($this->children);
    }

    /**
     * {@inheritdoc}
     */
    public function createView(FormView $parent = null)
    {
        if (null === $parent && $this->parent) {
            $parent = $this->parent->createView();
        }

        return $this->config->getType()->createView($this, $parent);
    }

    /**
     * Normalizes the value if a normalization transformer is set.
     *
     * @param mixed $value The value to transform
     *
     * @return string
     */
    private function modelToNorm($value)
    {
        foreach ($this->config->getModelTransformers() as $transformer) {
            $value = $transformer->transform($value);
        }

        return $value;
    }

    /**
     * Reverse transforms a value if a normalization transformer is set.
     *
     * @param string $value The value to reverse transform
     *
     * @return mixed
     */
    private function normToModel($value)
    {
        $transformers = $this->config->getModelTransformers();

        for ($i = count($transformers) - 1; $i >= 0; --$i) {
            $value = $transformers[$i]->reverseTransform($value);
        }

        return $value;
    }

    /**
     * Transforms the value if a value transformer is set.
     *
     * @param mixed $value The value to transform
     *
     * @return string
     */
    private function normToView($value)
    {
        // Scalar values should  be converted to strings to
        // facilitate differentiation between empty ("") and zero (0).
        // Only do this for simple forms, as the resulting value in
        // compound forms is passed to the data mapper and thus should
        // not be converted to a string before.
        if (!$this->config->getViewTransformers() && !$this->config->getCompound()) {
            return null === $value || is_scalar($value) ? (string) $value : $value;
        }

        foreach ($this->config->getViewTransformers() as $transformer) {
            $value = $transformer->transform($value);
        }

        return $value;
    }

    /**
     * Reverse transforms a value if a value transformer is set.
     *
     * @param string $value The value to reverse transform
     *
     * @return mixed
     */
    private function viewToNorm($value)
    {
        $transformers = $this->config->getViewTransformers();

        if (!$transformers) {
            return '' === $value ? null : $value;
        }

        for ($i = count($transformers) - 1; $i >= 0; --$i) {
            $value = $transformers[$i]->reverseTransform($value);
        }

        return $value;
    }
}
