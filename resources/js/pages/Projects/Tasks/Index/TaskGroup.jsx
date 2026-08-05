import useTaskDrawerStore from '@/hooks/store/useTaskDrawerStore';
import usePreferences from '@/hooks/usePreferences';
import { Draggable, Droppable } from '@hello-pangea/dnd';
import { ActionIcon, Button, Flex, Group, Text, Tooltip, rem } from '@mantine/core';
import { IconChevronDown, IconChevronUp, IconGripVertical, IconPlus } from '@tabler/icons-react';
import { useState } from 'react';
import Task from './Task';
import TaskGroupActions from './TaskGroupActions';
import classes from './css/TaskGroup.module.css';

// In list view, longer groups are cut off until the user asks for the rest.
// Kanban columns scroll in their own viewport, so they always show everything.
const COLLAPSED_LIMIT = 10;

export default function TaskGroup({ group, tasks, ...props }) {
  const { openCreateTask } = useTaskDrawerStore();
  const { tasksView } = usePreferences();
  const [showAll, setShowAll] = useState(false);

  const collapsible = tasksView === 'list';
  const hiddenCount = collapsible ? tasks.length - COLLAPSED_LIMIT : 0;
  // Always a prefix of the full list, so a rendered task's index still matches
  // its index in the store — which is what drag-and-drop reordering posts.
  const visibleTasks = hiddenCount > 0 && !showAll ? tasks.slice(0, COLLAPSED_LIMIT) : tasks;

  return (
    <Draggable
      draggableId={group.id.toString()}
      {...props}
    >
      {(provided, snapshot) => (
        <div
          className={`${classes.row} ${snapshot.isDragging && classes.itemDragging}`}
          ref={provided.innerRef}
          {...provided.draggableProps}
        >
          <div
            className={classes.group}
            style={group.color ? { backgroundColor: group.color } : undefined}
          >
            <Group>
              <div
                {...provided.dragHandleProps}
                className={classes.dragHandle}
              >
                <IconGripVertical
                  style={{
                    width: rem(18),
                    height: rem(18),
                    display:
                      can('reorder task group') && !route().params.archived ? 'inline' : 'none',
                  }}
                  stroke={1.5}
                />
              </div>
              <Text
                size='xl'
                fw={700}
              >
                {group.name}
              </Text>
              <TaskGroupActions
                group={group}
                className={classes.actions}
              />
            </Group>
            {!route().params.archived && can('create task') && (
              <Tooltip
                label='Add task'
                openDelay={1000}
                withArrow
              >
                <ActionIcon
                  variant='filled'
                  size='md'
                  radius='xl'
                  onClick={() => openCreateTask(group.id)}
                >
                  <IconPlus
                    style={{ width: rem(18), height: rem(18) }}
                    stroke={2}
                  />
                </ActionIcon>
              </Tooltip>
            )}
          </div>
          <Droppable
            droppableId={`group-${group.id}-tasks`}
            type='task'
          >
            {(provided, snapshot) => (
              <Flex
                direction='column'
                gap='3px'
                ref={provided.innerRef}
                {...provided.droppableProps}
                className={snapshot.isDraggingOver ? 'isDraggingOver' : ''}
              >
                {visibleTasks.map((task, index) => (
                  <Task
                    key={task.id}
                    task={task}
                    index={index}
                  />
                ))}
                <div className={classes.placeholder}>{provided.placeholder}</div>
              </Flex>
            )}
          </Droppable>

          {hiddenCount > 0 && (
            <Button
              variant='subtle'
              size='compact-sm'
              radius='xl'
              className={classes.showAll}
              rightSection={
                showAll ? (
                  <IconChevronUp
                    size={14}
                    stroke={1.5}
                  />
                ) : (
                  <IconChevronDown
                    size={14}
                    stroke={1.5}
                  />
                )
              }
              onClick={() => setShowAll(current => !current)}
            >
              {showAll ? `Show less` : `Show all ${tasks.length}`}
            </Button>
          )}
        </div>
      )}
    </Draggable>
  );
}
