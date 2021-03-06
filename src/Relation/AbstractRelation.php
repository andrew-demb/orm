<?php
/**
 * Cycle DataMapper ORM
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
declare(strict_types=1);

namespace Cycle\ORM\Relation;

use Cycle\ORM\Exception\RelationException;
use Cycle\ORM\Heap\Node;
use Cycle\ORM\MapperInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Promise\PromiseInterface;
use Cycle\ORM\Promise\ReferenceInterface;
use Cycle\ORM\Relation;
use Cycle\ORM\Schema;
use Cycle\ORM\Select\SourceInterface;
use Cycle\ORM\Select\SourceProviderInterface;

abstract class AbstractRelation implements RelationInterface
{
    use Traits\ContextTrait;

    /** @var ORMInterface|SourceProviderInterface @internal */
    protected $orm;

    /** @var string */
    protected $name;

    /** @var string */
    protected $target;

    /** @var array */
    protected $schema;

    /** @var string */
    protected $innerKey;

    /** @var string */
    protected $outerKey;

    /**
     * @param ORMInterface $orm
     * @param string       $name
     * @param string       $target
     * @param array        $schema
     */
    public function __construct(ORMInterface $orm, string $name, string $target, array $schema)
    {
        $this->orm = $orm;
        $this->name = $name;
        $this->target = $target;
        $this->schema = $schema;
        $this->innerKey = $schema[Relation::INNER_KEY];
        $this->outerKey = $schema[Relation::OUTER_KEY];
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function isCascade(): bool
    {
        return $this->schema[Relation::CASCADE] ?? false;
    }

    /**
     * @inheritdoc
     */
    public function init(array $data): array
    {
        $item = $this->orm->make($this->target, $data, Node::MANAGED);

        return [$item, $item];
    }

    /**
     * @inheritdoc
     */
    public function extract($data)
    {
        return $data;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        // this is incorrect class
        return sprintf("%s(%s)->%s", $this->name, get_class($this), $this->target);
    }

    /**
     * Indicates that relation can not be nullable.
     *
     * @return bool
     */
    protected function isRequired(): bool
    {
        if (array_key_exists(Relation::NULLABLE, $this->schema)) {
            return !$this->schema[Relation::NULLABLE];
        }

        return true;
    }

    /**
     * Get Node for the given entity. Null if entity does not exists. Automatically
     * register entity claims.
     *
     * @param object $entity
     * @param int    $claim
     * @return Node|null
     */
    protected function getNode($entity, int $claim = 0): ?Node
    {
        if (is_null($entity)) {
            return null;
        }

        if ($entity instanceof ReferenceInterface) {
            return new Node(Node::PROMISED, $entity->__scope(), $entity->__role());
        }

        $node = $this->orm->getHeap()->get($entity);

        if (is_null($node)) {
            $node = new Node(Node::NEW, [], $this->orm->getMapper($entity)->getRole());
            $this->orm->getHeap()->attach($entity, $node);
        }

        if ($claim === 1) {
            $node->getState()->addClaim();
        }

        if ($claim === -1) {
            $node->getState()->decClaim();
        }

        return $node;
    }

    /**
     * Get the source associated with the role.
     *
     * @param string|null $role
     * @return SourceInterface
     */
    protected function getSource(string $role = null): SourceInterface
    {
        return $this->orm->getSource($role ?? $this->target);
    }

    /**
     * Get the mapper associated with a role.
     *
     * @param string|null $role
     * @return MapperInterface
     */
    protected function getMapper(string $role = null): MapperInterface
    {
        return $this->orm->getMapper($role ?? $this->target);
    }

    /**
     * @param Node   $node
     * @param string $field
     * @return string
     */
    protected function columnName(Node $node, string $field): string
    {
        return $this->orm->getSchema()->define($node->getRole(), Schema::COLUMNS)[$field] ?? $field;
    }

    /**
     * Assert that given entity is allowed for the relation.
     *
     * @param Node $relNode
     *
     * @throws RelationException
     */
    protected function assertValid(Node $relNode)
    {
        if ($relNode->getRole() != $this->target) {
            throw new RelationException(sprintf("Unable to link %s, given `%s`", $this, $relNode->getRole()));
        }
    }

    /**
     * Resolve the reference to the object.
     *
     * @param ReferenceInterface $reference
     * @return mixed|null
     */
    protected function resolve(ReferenceInterface $reference)
    {
        if ($reference instanceof PromiseInterface) {
            return $reference->__resolve();
        }

        $scope = $reference->__scope();
        return $this->orm->get($reference->__role(), key($scope), current($scope), true);
    }
}